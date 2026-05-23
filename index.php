<?php
session_start();
require_once 'config.php';
$pdo = getDbConnection();
ensureSchema($pdo);

// --- Logic Helpers ---

/**
 * Performs a ping with retry logic
 * Returns: [
 *   'status_code' => int,
 *   'response_time' => float (first attempt time),
 *   'cumulative_time' => float (total time including all retries),
 *   'total_attempts' => int,
 *   'health_status' => 'green'|'yellow'|'red'
 * ]
 */
function pingWithRetries($url, $timeout, $maxRetries) {
    $cumulativeTime = 0;
    $finalStatusCode = 0;
    $firstAttemptTime = 0;
    $attemptCount = 0;
    $allFailed = true;

    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
        $attemptCount++;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'UptimeMonitor/1.0',
            CURLOPT_FAILONERROR => false
        ]);
        
        $start = microtime(true);
        curl_exec($ch);
        $elapsed = (microtime(true) - $start) * 1000; // Convert to ms
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Store first attempt time
        if ($attempt === 0) {
            $firstAttemptTime = round($elapsed, 2);
        }

        $cumulativeTime += $elapsed;
        $finalStatusCode = $http_code;

        // Check if this attempt succeeded (HTTP code < 400, or code > 0)
        $isSuccess = ($http_code > 0 && $http_code < 400);
        
        if ($isSuccess) {
            $allFailed = false;
            break; // Success! Stop retrying
        }
    }

    // Determine health status
    if ($attemptCount === 1 && $finalStatusCode > 0 && $finalStatusCode < 400) {
        // Green: succeeded on first try
        $healthStatus = 'green';
    } elseif (!$allFailed) {
        // Yellow: failed at least once, but eventually succeeded
        $healthStatus = 'yellow';
    } else {
        // Red: failed all retries
        $healthStatus = 'red';
    }

    return [
        'status_code' => $finalStatusCode,
        'response_time' => $firstAttemptTime,
        'cumulative_time' => round($cumulativeTime, 2),
        'total_attempts' => $attemptCount,
        'health_status' => $healthStatus
    ];
}

function pingSite($pdo, $id, $url, $alias, $timeout = 30, $retries = 2) {
    $result = pingWithRetries($url, $timeout, $retries);
    
    $pdo->prepare(
        "INSERT INTO logs (site_id, status_code, response_time, cumulative_time, total_attempts, health_status) 
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([
        $id,
        $result['status_code'],
        $result['response_time'],
        $result['cumulative_time'],
        $result['total_attempts'],
        $result['health_status']
    ]);
    
    $pdo->prepare("UPDATE sites SET last_status = ?, last_health_status = ? WHERE id = ?")
        ->execute([$result['status_code'], $result['health_status'], $id]);

    return $result;
}

// Load config defaults
$configDefaults = $pdo->query("SELECT setting_key, setting_value FROM config")->fetchAll(PDO::FETCH_KEY_PAIR);
$defaultTimeout = (int)($configDefaults['default_timeout'] ?? 30);
$defaultRetries = (int)($configDefaults['default_retries'] ?? 2);
$adminUsername = trim((string)($configDefaults['admin_username'] ?? ''));
$adminPasswordHash = (string)($configDefaults['admin_password_hash'] ?? '');
$adminPasswordSalt = (string)($configDefaults['admin_password_salt'] ?? '');
$isAdminConfigured = $adminUsername !== '' && $adminPasswordHash !== '' && $adminPasswordSalt !== '';

if (isset($_POST['admin_logout'])) {
    unset($_SESSION['is_admin_authenticated'], $_SESSION['admin_username']);
    session_regenerate_id(true);
    header('Location: index.php');
    exit;
}

if (!$isAdminConfigured && isset($_POST['setup_admin'])) {
    $setupUsername = trim((string)($_POST['setup_username'] ?? ''));
    $setupPassword = (string)($_POST['setup_password'] ?? '');
    $setupPasswordConfirm = (string)($_POST['setup_password_confirm'] ?? '');

    if (strlen($setupUsername) < 3) {
        $_SESSION['auth_flash_message'] = 'Username must be at least 3 characters.';
        $_SESSION['auth_flash_type'] = 'error';
    } elseif (strlen($setupPassword) < 8) {
        $_SESSION['auth_flash_message'] = 'Password must be at least 8 characters.';
        $_SESSION['auth_flash_type'] = 'error';
    } elseif (!preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', $setupPassword)) {
        $_SESSION['auth_flash_message'] = 'Password must include at least one letter and one number.';
        $_SESSION['auth_flash_type'] = 'error';
    } elseif (!hash_equals($setupPassword, $setupPasswordConfirm)) {
        $_SESSION['auth_flash_message'] = 'Password confirmation does not match.';
        $_SESSION['auth_flash_type'] = 'error';
    } else {
        $salt = bin2hex(random_bytes(16));
        $passwordHash = password_hash($setupPassword . $salt, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            "INSERT INTO config (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );

        $stmt->execute(['admin_username', $setupUsername]);
        $stmt->execute(['admin_password_hash', $passwordHash]);
        $stmt->execute(['admin_password_salt', $salt]);

        $_SESSION['is_admin_authenticated'] = true;
        $_SESSION['admin_username'] = $setupUsername;
        $_SESSION['flash_message'] = 'Admin account setup complete.';
        $_SESSION['flash_type'] = 'success';
    }

    header('Location: index.php');
    exit;
}

if ($isAdminConfigured && isset($_POST['admin_login'])) {
    $loginUsername = trim((string)($_POST['admin_username'] ?? ''));
    $loginPassword = (string)($_POST['admin_password'] ?? '');
    $isValidLogin = hash_equals($adminUsername, $loginUsername) && password_verify($loginPassword . $adminPasswordSalt, $adminPasswordHash);

    if ($isValidLogin) {
        session_regenerate_id(true);
        $_SESSION['is_admin_authenticated'] = true;
        $_SESSION['admin_username'] = $adminUsername;
        $_SESSION['flash_message'] = 'Signed in successfully.';
        $_SESSION['flash_type'] = 'success';
    } else {
        $_SESSION['auth_flash_message'] = 'Invalid username or password.';
        $_SESSION['auth_flash_type'] = 'error';
    }

    header('Location: index.php');
    exit;
}

if (!$isAdminConfigured || empty($_SESSION['is_admin_authenticated'])) {
    $authFlashMessage = $_SESSION['auth_flash_message'] ?? '';
    $authFlashType = $_SESSION['auth_flash_type'] ?? 'error';
    unset($_SESSION['auth_flash_message'], $_SESSION['auth_flash_type']);
    ?>
<!DOCTYPE html>
<html>
<!DOCTYPE html>
<html>
<head>
    <title>Uptime Dashboard Access</title>
    <link rel="stylesheet" href="styles.css?v=<?= time() . rand() ?>">
    <script defer src="app.js?v=<?= time() . rand() ?>"></script>
</head>
<body class="auth-page">
    <div class="auth-shell">
        <?php if ($authFlashMessage): ?>
            <div class="flash <?= htmlspecialchars($authFlashType) ?>"><?= htmlspecialchars($authFlashMessage) ?></div>
        <?php endif; ?>

        <?php if (!$isAdminConfigured): ?>
            <h1>Create Admin Account</h1>
            <p>Set up your admin username and password to secure this dashboard.</p>
            <form method="POST">
                <div class="field">
                    <label for="setup_username">Admin Username</label>
                    <input type="text" id="setup_username" name="setup_username" minlength="3" required>
                </div>
                <div class="field">
                    <label for="setup_password">Admin Password</label>
                    <input type="password" id="setup_password" name="setup_password" minlength="8" title="Use 8 or more characters with at least one letter and one number" required>
                </div>
                <div class="field">
                    <label for="setup_password_confirm">Confirm Password</label>
                    <input type="password" id="setup_password_confirm" name="setup_password_confirm" minlength="8" required>
                </div>
                <button type="submit" name="setup_admin">Create Admin Account</button>
            </form>
            <div class="hint">Use 8 or more characters with at least one letter and one number. Passwords are salted and hashed before being stored in the database.</div>
        <?php else: ?>
            <h1>Admin Login</h1>
            <p>Sign in to access uptime settings and controls.</p>
            <form method="POST">
                <div class="field">
                    <label for="admin_username">Username</label>
                    <input type="text" id="admin_username" name="admin_username" autocomplete="username" required>
                </div>
                <div class="field">
                    <label for="admin_password">Password</label>
                    <input type="password" id="admin_password" name="admin_password" autocomplete="current-password" required>
                </div>
                <button type="submit" name="admin_login">Sign In</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
    exit;
}

// --- Actions ---

if (isset($_POST['delete_site'])) {
    $siteId = (int)$_POST['site_id'];
    $pdo->prepare("DELETE FROM sites WHERE id = ?")->execute([$siteId]);
    header('Location: index.php');
    exit;
}

if (isset($_POST['toggle_morning_summary'])) {
    $siteId = (int)$_POST['site_id'];
    $enabled = isset($_POST['morning_summary_enabled']) ? (int)$_POST['morning_summary_enabled'] : 0;
    $enabled = $enabled === 1 ? 1 : 0;

    $pdo->prepare("UPDATE sites SET morning_summary_enabled = ? WHERE id = ?")
        ->execute([$enabled, $siteId]);

    $_SESSION['flash_message'] = $enabled === 1
        ? 'Morning summary alerts enabled for this site.'
        : 'Morning summary alerts disabled for this site.';
    $_SESSION['flash_type'] = 'success';
    header('Location: index.php');
    exit;
}

if (isset($_POST['send_summary'])) {
    $siteId = (int)$_POST['site_id'];
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $site = $stmt->fetch();

    if ($site) {
        $timeout = (int)($site['timeout_seconds'] ?? $defaultTimeout);
        $retries = (int)($site['retries'] ?? $defaultRetries);
        $today = (new DateTimeImmutable('now', new DateTimeZone($configDefaults['timezone'] ?? 'UTC')))->format('Y-m-d');
        $result = pingSite($pdo, $site['id'], $site['url'], $site['alias'], $timeout, $retries);
        $metrics = calculateSiteSummaryMetrics($pdo, (int)$site['id'], 1);
        $message = buildMorningDigestMessage($site, $metrics, $result);
        $telegramBotToken = (string)($configDefaults['telegram_bot_token'] ?? '');
        $telegramChatId = (string)($configDefaults['telegram_chat_id'] ?? '');

        if ($telegramBotToken === '' || $telegramChatId === '') {
            $_SESSION['flash_message'] = 'Telegram token and chat id are required to send a summary.';
            $_SESSION['flash_type'] = 'error';
        } else {
            $telegramResult = sendTelegramMessage($telegramBotToken, $telegramChatId, $message);

            if ($telegramResult['ok']) {
                $pdo->prepare("UPDATE sites SET last_morning_summary_sent = ? WHERE id = ?")
                    ->execute([$today, $site['id']]);
                $_SESSION['flash_message'] = 'Summary sent successfully.';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Summary could not be sent to Telegram.';
                $_SESSION['flash_type'] = 'error';
            }
        }
    }

    header('Location: index.php');
    exit;
}

if (isset($_POST['ping_site'])) {
    $siteId = (int)$_POST['site_id'];
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $site = $stmt->fetch();

    if ($site) {
        $timeout = (int)($site['timeout_seconds'] ?? $defaultTimeout);
        $retries = (int)($site['retries'] ?? $defaultRetries);
        pingSite($pdo, $site['id'], $site['url'], $site['alias'], $timeout, $retries);
    }

    header('Location: index.php');
    exit;
}

if (isset($_POST['add_site'])) {
    $timeout = max(1, (int)($_POST['timeout_seconds'] ?? $defaultTimeout));
    $retries = max(0, (int)($_POST['retries'] ?? $defaultRetries));
    
    $stmt = $pdo->prepare("INSERT INTO sites (url, alias, timeout_seconds, retries, morning_summary_enabled) VALUES (?, ?, ?, ?, 0)");
    $stmt->execute([$_POST['url'], $_POST['alias'], $timeout, $retries]);
    $newId = $pdo->lastInsertId();
    
    pingSite($pdo, $newId, $_POST['url'], $_POST['alias'], $timeout, $retries); // Instant check
}

if (isset($_POST['update_site_settings'])) {
    $siteId = (int)$_POST['site_id'];
    $timeout = max(1, (int)$_POST['timeout_seconds']);
    $retries = max(0, (int)$_POST['retries']);
    
    $pdo->prepare("UPDATE sites SET timeout_seconds = ?, retries = ? WHERE id = ?")
        ->execute([$timeout, $retries, $siteId]);
    
    $_SESSION['flash_message'] = 'Site settings updated successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: index.php');
    exit;
}

if (isset($_POST['clear_logs'])) {
    $days = (int)$_POST['log_days'];
    if ($days === 0) {
        $pdo->query("TRUNCATE TABLE logs");
    } else {
        $pdo->prepare("DELETE FROM logs WHERE checked_at < DATE_SUB(NOW(), INTERVAL ? DAY)")->execute([$days]);
    }
}

if (isset($_POST['save_monitoring_settings']) || isset($_POST['save_settings'])) {
    $settings = [
        'quiet_start_hour' => (int)$_POST['quiet_start_hour'],
        'quiet_end_hour' => (int)$_POST['quiet_end_hour'],
        'default_timeout' => max(1, (int)$_POST['default_timeout']),
        'default_retries' => max(0, (int)$_POST['default_retries']),
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO config (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );

    foreach ($settings as $key => $value) {
        $stmt->execute([$key, (string)$value]);
    }

    $_SESSION['flash_message'] = 'Monitoring settings saved successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: index.php');
    exit;
}

// Save per-site morning summary options
if (isset($_POST['save_morning_summary_settings'])) {
    $siteId = (int)$_POST['site_id'];
    $onlyOnIssue = isset($_POST['morning_summary_only_on_issue']) && $_POST['morning_summary_only_on_issue'] === '1' ? 1 : 0;
    $frequency = in_array($_POST['morning_summary_frequency'] ?? 'daily', ['daily','weekly','monthly'], true) ? $_POST['morning_summary_frequency'] : 'daily';

    $pdo->prepare("UPDATE sites SET morning_summary_only_on_issue = ?, morning_summary_frequency = ? WHERE id = ?")
        ->execute([$onlyOnIssue, $frequency, $siteId]);

    $_SESSION['flash_message'] = 'Morning summary options saved for the site.';
    $_SESSION['flash_type'] = 'success';
    header('Location: index.php');
    exit;
}

if (isset($_POST['save_telegram_settings'])) {
    $settings = [
        'telegram_bot_token' => trim($_POST['telegram_bot_token']),
        'telegram_chat_id' => trim($_POST['telegram_chat_id']),
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO config (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );

    foreach ($settings as $key => $value) {
        $stmt->execute([$key, (string)$value]);
    }

    $_SESSION['flash_message'] = 'Telegram settings saved successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: index.php');
    exit;
}

if (isset($_POST['test_telegram'])) {
    $telegramBotTokenInput = trim($_POST['telegram_bot_token'] ?? '');
    $telegramChatIdInput = trim($_POST['telegram_chat_id'] ?? '');
    $currentConfig = $pdo->query("SELECT setting_key, setting_value FROM config")->fetchAll(PDO::FETCH_KEY_PAIR);

    $telegramBotToken = $telegramBotTokenInput !== '' ? $telegramBotTokenInput : ($currentConfig['telegram_bot_token'] ?? '');
    $telegramChatId = $telegramChatIdInput !== '' ? $telegramChatIdInput : ($currentConfig['telegram_chat_id'] ?? '');

    if ($telegramBotToken === '' || $telegramChatId === '') {
        $_SESSION['flash_message'] = 'Telegram token and chat id are required for a test message.';
        $_SESSION['flash_type'] = 'error';
    } else {
        $verification = verifyTelegramSettings($telegramBotToken, $telegramChatId);

        if (!$verification['ok']) {
            $details = $verification['description'] ?: ($verification['response'] ?: 'Unknown Telegram verification failure.');
            $_SESSION['flash_message'] = "Telegram verification failed: {$details}";
            $_SESSION['flash_type'] = 'error';
        } else {
            $telegramResult = sendTelegramMessage($telegramBotToken, $telegramChatId, 'Test message from Uptime Dashboard: Telegram alerts are configured correctly.');

            if ($telegramResult['ok']) {
                $httpStatus = $telegramResult['http_code'] ? "HTTP {$telegramResult['http_code']}" : 'no HTTP status';
                $_SESSION['flash_message'] = "Telegram settings verified and test message sent successfully ({$httpStatus}).";
                $_SESSION['flash_type'] = 'success';
            } else {
                $errorDetails = $telegramResult['description'] ?: ($telegramResult['response'] ?: 'No response from Telegram API.');
                $_SESSION['flash_message'] = "Telegram settings verified, but the test message failed: {$errorDetails}";
                $_SESSION['flash_type'] = 'error';
            }
        }
    }

    header('Location: index.php');
    exit;
}

if (isset($_POST['verify_telegram'])) {
    $telegramBotTokenInput = trim($_POST['telegram_bot_token'] ?? '');
    $telegramChatIdInput = trim($_POST['telegram_chat_id'] ?? '');
    $currentConfig = $pdo->query("SELECT setting_key, setting_value FROM config")->fetchAll(PDO::FETCH_KEY_PAIR);

    $telegramBotToken = $telegramBotTokenInput !== '' ? $telegramBotTokenInput : ($currentConfig['telegram_bot_token'] ?? '');
    $telegramChatId = $telegramChatIdInput !== '' ? $telegramChatIdInput : ($currentConfig['telegram_chat_id'] ?? '');

    if ($telegramBotToken === '' || $telegramChatId === '') {
        $_SESSION['flash_message'] = 'Telegram token and chat id are required to verify settings.';
        $_SESSION['flash_type'] = 'error';
    } else {
        $verification = verifyTelegramSettings($telegramBotToken, $telegramChatId);

        if ($verification['ok']) {
            $_SESSION['flash_message'] = 'Telegram settings verified successfully.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $details = $verification['description'] ?: ($verification['response'] ?: 'Unknown Telegram verification failure.');
            $_SESSION['flash_message'] = "Telegram settings verification failed: {$details}";
            $_SESSION['flash_type'] = 'error';
        }
    }

    header('Location: index.php');
    exit;
}

// JSON Endpoint for Chart Data (includes cumulative time and health status)
if (isset($_GET['get_stats'])) {
    header('Content-Type: application/json');
    $configRows = $pdo->query("SELECT setting_key, setting_value FROM config")->fetchAll(PDO::FETCH_KEY_PAIR);
    $serverTimezone = new DateTimeZone($configRows['timezone'] ?? 'UTC');
    // If a 'days' parameter was provided and is >= 7, return hourly-aggregated
    // max cumulative_time per hour (and a conservative health_status for the
    // hour) to reduce payload size and improve chart performance.
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 0;

    if ($days >= 7) {
        // Compute cutoff in PHP to avoid issues with binding inside INTERVAL in SQL.
        $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));

        // Group by 4-hour bucket and return one point per bucket (timestamp at bucket start).
        $stmt = $pdo->prepare(
            "SELECT
                (FLOOR(UNIX_TIMESTAMP(checked_at) / 14400) * 14400 * 1000) AS checked_at_ms,
                FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(checked_at) / 14400) * 14400) AS checked_at,
                MAX(cumulative_time) AS cumulative_time,
                -- conservative health status: red if any red in bucket, else yellow if any yellow, else green
                CASE
                    WHEN SUM(CASE WHEN health_status = 'red' THEN 1 ELSE 0 END) > 0 THEN 'red'
                    WHEN SUM(CASE WHEN health_status = 'yellow' THEN 1 ELSE 0 END) > 0 THEN 'yellow'
                    ELSE 'green'
                END AS health_status,
                MAX(total_attempts) AS total_attempts
             FROM logs
             WHERE site_id = ? AND checked_at >= ?
             GROUP BY FLOOR(UNIX_TIMESTAMP(checked_at) / 14400)
             ORDER BY checked_at ASC"
        );
        $stmt->execute([$_GET['get_stats'], $cutoff]);
        $rows = $stmt->fetchAll();

        // Ensure checked_at_ms is integer
        foreach ($rows as &$row) {
            if (isset($row['checked_at_ms']) && is_numeric($row['checked_at_ms'])) {
                $row['checked_at_ms'] = (int)$row['checked_at_ms'];
            } else {
                $row['checked_at_ms'] = (int)(strtotime((string)$row['checked_at']) * 1000);
            }
        }
        unset($row);

        // Tell the client which 'days' window this response corresponds to
        header('X-Requested-Days: ' . $days);
        echo json_encode($rows);
        exit;
    }

    // Default: return raw rows. If a small `days` window is requested (<7)
    // the DB will filter by that window to avoid returning the whole history.
    if ($days > 0 && $days < 7) {
        $stmt = $pdo->prepare(
            "SELECT cumulative_time, health_status, total_attempts, checked_at, (UNIX_TIMESTAMP(checked_at) * 1000) AS checked_at_ms FROM logs
             WHERE site_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY checked_at ASC"
        );
        $stmt->execute([$_GET['get_stats'], $days]);
    } else {
        // No small-days window requested: return recent rows but limit to avoid huge payloads
        $stmt = $pdo->prepare(
            "SELECT cumulative_time, health_status, total_attempts, checked_at, (UNIX_TIMESTAMP(checked_at) * 1000) AS checked_at_ms FROM logs
             WHERE site_id = ? ORDER BY checked_at ASC LIMIT 1000"
        );
        $stmt->execute([$_GET['get_stats']]);
    }
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        if (isset($row['checked_at_ms']) && is_numeric($row['checked_at_ms'])) {
            $row['checked_at_ms'] = (int)$row['checked_at_ms'];
        } else {
            $checkedAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['checked_at'], $serverTimezone);
            if ($checkedAt instanceof DateTimeImmutable) {
                $row['checked_at_ms'] = $checkedAt->getTimestamp() * 1000;
            } else {
                $row['checked_at_ms'] = (int)(strtotime((string)$row['checked_at']) * 1000);
            }
        }
    }
    unset($row);
    echo json_encode($rows);
    exit;
}

function calculateSiteSummaryMetrics(PDO $pdo, int $siteId, int $days): array {
    $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
    $stmt = $pdo->prepare(
        "SELECT health_status FROM logs WHERE site_id = ? AND checked_at > ? ORDER BY checked_at ASC"
    );
    $stmt->execute([$siteId, $cutoff]);
    $logs = $stmt->fetchAll();

    if (empty($logs)) {
        return ['uptime' => 0, 'outages' => 0];
    }

    $totalChecks = count($logs);
    $successfulChecks = 0;
    $outageCount = 0;
    $inOutage = false;

    foreach ($logs as $log) {
        if ($log['health_status'] === 'green' || $log['health_status'] === 'yellow') {
            $successfulChecks++;
            $inOutage = false;
            continue;
        }

        if ($log['health_status'] === 'red' && !$inOutage) {
            $outageCount++;
            $inOutage = true;
        }
    }

    return [
        'uptime' => $totalChecks > 0 ? round(($successfulChecks / $totalChecks) * 100, 2) : 0,
        'outages' => $outageCount,
    ];
}

function buildMorningDigestMessage(array $site, array $metrics, array $result): string {
    $uptimePercent = number_format((float)$metrics['uptime'], 2);
    $outageCount = (int)$metrics['outages'];

    if (($result['health_status'] ?? '') === 'red') {
        $message = "🌅 Morning Catch-up: {$site['alias']} is still DOWN (Status: {$result['status_code']})\n";
    } elseif (($result['health_status'] ?? '') === 'yellow') {
        $message = "🌅 Morning Summary: {$site['alias']} recovered after retries.\n";
    } else {
        $message = "🌅 Morning Summary: {$site['alias']} is UP.\n";
    }

    $message .= "📊 Last 24h: {$uptimePercent}% uptime | {$outageCount} outage" . ($outageCount !== 1 ? 's' : '');

    return $message;
}

// ... (Rest of login/setup logic from previous version) ...

$sites = $pdo->query("SELECT * FROM sites")->fetchAll();
$config = $pdo->query("SELECT setting_key, setting_value FROM config")->fetchAll(PDO::FETCH_KEY_PAIR);
$quietStartHour = (int)($config['quiet_start_hour'] ?? $config['active_end_hour'] ?? 22);
$quietEndHour = (int)($config['quiet_end_hour'] ?? $config['active_start_hour'] ?? 8);
$telegramBotToken = $config['telegram_bot_token'] ?? '';
$telegramChatId = $config['telegram_chat_id'] ?? '';
$dashboardTimezone = $config['timezone'] ?? 'UTC';
$flashMessage = $_SESSION['flash_message'] ?? '';
 $flashType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message']);
unset($_SESSION['flash_type']);

// Generate test data if requested
if (isset($_GET['generate_test_data']) && $_GET['generate_test_data'] === '1') {
    foreach ($sites as $site) {
        $siteId = $site['id'];
        // Generate 50 data points spread across the last 30 days
        for ($i = 0; $i < 50; $i++) {
            $daysAgo = floor(($i / 50) * 30); // Spread from 0 to 30 days
            $hoursAgo = ($i % 6) * 4; // 4 hour intervals
            $timestamp = date('Y-m-d H:i:s', time() - ($daysAgo * 86400) - ($hoursAgo * 3600));
            
            $statuses = ['green', 'green', 'green', 'yellow', 'red'];
            $status = $statuses[array_rand($statuses)];
            $responseTime = rand(50, 800);
            $cumulativeTime = $status === 'green' ? $responseTime : $responseTime * rand(2, 5);
            
            $pdo->prepare(
                "INSERT INTO logs (site_id, status_code, response_time, health_status, total_attempts, cumulative_time, checked_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $siteId,
                $status === 'green' ? 200 : ($status === 'yellow' ? 200 : 503),
                $responseTime,
                $status,
                $status === 'yellow' ? 2 : 1,
                $cumulativeTime,
                $timestamp
            ]);
        }
    }
    $_SESSION['flash_message'] = 'Test data generated! Refresh to see 30 days of monitoring data spread across the chart.';
    $_SESSION['flash_type'] = 'success';
    header('Location: index.php');
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Boomarian Uptime Dashboard</title>
    <link rel="stylesheet" href="styles.css?v=<?= time() . rand() ?>">
    <script>window.UPTIME_TIMEZONE = <?= json_encode($dashboardTimezone) ?>;</script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script defer src="app.js?v=<?= time() . rand() ?>"></script>
</head>
<body class="dashboard-page">
    <div class="container">
        <div class="hero">
            <h1>Uptime Dashboard</h1>
            <p>Track checks, inspect trends, ping on demand, and clean up sites without leaving the page.</p>
            <div style="margin-top: 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <span style="color: #cbd5e1; font-weight: 700;">Signed in as <?= htmlspecialchars((string)($_SESSION['admin_username'] ?? 'admin')) ?></span>
                <form method="POST" class="inline">
                    <button type="submit" name="admin_logout" class="btn-secondary btn-mini">Logout</button>
                </form>
            </div>
        </div>

        <?php if ($flashMessage): ?>
            <div class="flash <?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flashMessage) ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Current Status</h2>
            <table>
                <tr><th>Morning Summary</th><th>Alias</th><th>Status</th><th>Summary</th><th>Actions</th></tr>
                <?php foreach ($sites as $s): 
                    $healthStatus = $s['last_health_status'] ?? 'unknown';
                    $summaryEnabled = (int)($s['morning_summary_enabled'] ?? 0) === 1;
                    $summary1d = calculateSiteSummaryMetrics($pdo, (int)$s['id'], 1);
                    $summary30d = calculateSiteSummaryMetrics($pdo, (int)$s['id'], 30);
                    if ($healthStatus === 'green') {
                        $statusColor = 'bg-green';
                        $statusEmoji = '✅';
                    } elseif ($healthStatus === 'yellow') {
                        $statusColor = 'bg-yellow';
                        $statusEmoji = '🟨';
                    } elseif ($healthStatus === 'red') {
                        $statusColor = 'bg-red';
                        $statusEmoji = '🚨';
                    } else {
                        $statusColor = 'bg-gray';
                        $statusEmoji = '❓';
                    }
                ?>
                <tr>
                    <td>
                        <div class="actions">
                            <form method="POST" class="inline">
                                <input type="hidden" name="site_id" value="<?= $s['id'] ?>">
                                <input type="hidden" name="morning_summary_enabled" value="<?= $summaryEnabled ? 0 : 1 ?>">
                                <button type="submit" name="toggle_morning_summary" class="btn-secondary btn-mini"><?= $summaryEnabled ? 'Disable Summary' : 'Enable Summary' ?></button>
                            </form>

                            <form method="POST" class="inline">
                                <input type="hidden" name="site_id" value="<?= $s['id'] ?>">
                                <button type="submit" name="send_summary" class="btn-secondary btn-mini">Send Summary</button>
                            </form>

                            <!-- Per-site summary options: only send on issue and frequency -->
                            <form method="POST" class="inline">
                                <input type="hidden" name="site_id" value="<?= $s['id'] ?>">
                                <?php $onlyOnIssue = (int)($s['morning_summary_only_on_issue'] ?? 0) === 1; ?>
                                <label style="display:inline-flex;align-items:center;gap:6px;">
                                    <input type="checkbox" name="morning_summary_only_on_issue" value="1" <?= $onlyOnIssue ? 'checked' : '' ?>>
                                    <span style="font-size:12px">Only on issue</span>
                                </label>
                                <select name="morning_summary_frequency" style="margin-left:8px;font-size:12px">
                                    <?php $freq = htmlspecialchars($s['morning_summary_frequency'] ?? $config['morning_summary_frequency'] ?? 'daily'); ?>
                                    <option value="daily" <?= $freq === 'daily' ? 'selected' : '' ?>>Daily</option>
                                    <option value="weekly" <?= $freq === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                    <option value="monthly" <?= $freq === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                </select>
                                <button type="submit" name="save_morning_summary_settings" class="btn-secondary btn-mini" style="margin-left:8px">Save</button>
                            </form>
                        </div>
                    </td>
                    <td><strong><?= htmlspecialchars($s['alias']) ?></strong></td>
                    <td><span class="status <?= $statusColor ?>"><?= $statusEmoji ?> <?= htmlspecialchars($healthStatus) ?></span></td>
                    <td>
                        <div class="site-stats">
                            <div><strong>1D:</strong> <?= htmlspecialchars(number_format((float)$summary1d['uptime'], 2)) ?>% uptime, <?= (int)$summary1d['outages'] ?> outage<?= $summary1d['outages'] === 1 ? '' : 's' ?></div>
                            <div><strong>30D:</strong> <?= htmlspecialchars(number_format((float)$summary30d['uptime'], 2)) ?>% uptime, <?= (int)$summary30d['outages'] ?> outage<?= $summary30d['outages'] === 1 ? '' : 's' ?></div>
                            <button type="button" class="btn-secondary btn-mini" onclick="showChart(<?= (int)$s['id'] ?>, <?= htmlspecialchars(json_encode($s['alias']), ENT_QUOTES, 'UTF-8') ?>)">View Graph</button>
                        </div>
                    </td>
                    <td>
                        <div class="actions">
                            <form method="POST" class="inline">
                                <input type="hidden" name="site_id" value="<?= $s['id'] ?>">
                                <button type="submit" name="ping_site" class="btn-primary btn-mini">Ping Now</button>
                            </form>
                            <button type="button" class="btn-secondary btn-mini" onclick="openSettingsModal(<?= (int)$s['id'] ?>, <?= htmlspecialchars(json_encode($s['alias']), ENT_QUOTES, 'UTF-8') ?>, <?= (int)($s['timeout_seconds'] ?? $defaultTimeout) ?>, <?= (int)($s['retries'] ?? $defaultRetries) ?>)">Settings</button>
                            <button type="button" class="btn-danger btn-mini" onclick="openDeleteModal(<?= (int)$s['id'] ?>, <?= htmlspecialchars(json_encode($s['alias']), ENT_QUOTES, 'UTF-8') ?>)">Delete</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="card">
            <h3>Add Website</h3>
            <form method="POST" class="stack">
                <input type="text" name="alias" placeholder="Name" required>
                <input type="url" name="url" placeholder="https://..." required>
                <input type="number" name="timeout_seconds" placeholder="Timeout (seconds)" value="<?= $defaultTimeout ?>" min="1" required>
                <input type="number" name="retries" placeholder="Retries" value="<?= $defaultRetries ?>" min="0" required>
                <button type="submit" name="add_site" class="btn-primary">Add & Test</button>
            </form>
        </div>

        <div class="card">
            <h3>Log Management</h3>
            <form method="POST" class="stack">
                <label>Delete logs older than:</label>
                <select name="log_days">
                    <option value="7">7 Days</option>
                    <option value="30">30 Days</option>
                    <option value="0">All History</option>
                </select>
                <button type="submit" name="clear_logs" class="btn-danger">Cleanup Logs</button>
            </form>
            <p style="margin-top: 12px; font-size: 12px;"><a href="?generate_test_data=1" style="color: #cbd5e1; text-decoration: underline;">Generate test data (30 days spread)</a></p>
        </div>

        <div class="card">
            <h3>Monitoring Defaults</h3>
            <p class="modal-lead">Configure quiet hours and request behavior defaults for new sites.</p>
            <form method="POST" class="stack">
                <div class="field">
                    <label for="quiet_start_hour">Quiet Time Start</label>
                    <select name="quiet_start_hour" id="quiet_start_hour">
                        <?php for ($hour = 0; $hour < 24; $hour++): ?>
                            <option value="<?= $hour ?>" <?= $quietStartHour === $hour ? 'selected' : '' ?>><?= sprintf('%02d:00', $hour) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="quiet_end_hour">Quiet Time End</label>
                    <select name="quiet_end_hour" id="quiet_end_hour">
                        <?php for ($hour = 0; $hour < 24; $hour++): ?>
                            <option value="<?= $hour ?>" <?= $quietEndHour === $hour ? 'selected' : '' ?>><?= sprintf('%02d:00', $hour) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="default_timeout">Default Request Timeout (seconds)</label>
                    <input type="number" id="default_timeout" name="default_timeout" value="<?= $defaultTimeout ?>" min="1" required>
                </div>

                <div class="field">
                    <label for="default_retries">Default Retries</label>
                    <input type="number" id="default_retries" name="default_retries" value="<?= $defaultRetries ?>" min="0" required>
                </div>

                <button type="submit" name="save_monitoring_settings" class="btn-primary">Save Monitoring Settings</button>
            </form>
        </div>

        <div class="card">
            <h3>Telegram Alerts</h3>
            <p class="modal-lead">Manage Telegram bot credentials and test delivery.</p>
            <form method="POST" class="stack">
                <input type="text" name="telegram_bot_token" placeholder="Telegram bot token" value="<?= htmlspecialchars($telegramBotToken) ?>">
                <input type="text" name="telegram_chat_id" placeholder="Telegram chat id" value="<?= htmlspecialchars($telegramChatId) ?>">
                <button type="submit" name="save_telegram_settings" class="btn-primary">Save Telegram Settings</button>
                <button type="submit" name="verify_telegram" class="btn-secondary">Verify Telegram Settings</button>
                <button type="submit" name="test_telegram" class="btn-secondary">Test Telegram Message</button>
            </form>
        </div>
    </div>

    <div id="chartModal" class="modal" role="dialog" aria-modal="true">
        <div class="modal-content">
            <button class="modal-close" aria-label="Close" onclick="closeChart()">❌</button>
            <h3 id="modalTitle"></h3>
            <script>/* Ensure a safe global exists for inline onclick handlers before app.js loads */
                if (typeof window.setChartRange !== 'function') {
                    window.setChartRange = function(days) {
                        try { document.dispatchEvent(new CustomEvent('uptime-setChartRange', { detail: days })); } catch (e) { /* ignore */ }
                    };
                }
            </script>
            <div id="chartTimeRangeButtons" class="chart-controls" aria-label="Chart time range">
                <button type="button" class="btn-secondary chart-range-button is-active" data-days="1" onclick="setChartRange(1)">1D</button>
                <button type="button" class="btn-secondary chart-range-button" data-days="7" onclick="setChartRange(7)">7D</button>
                <button type="button" class="btn-secondary chart-range-button" data-days="30" onclick="setChartRange(30)">30D</button>
            </div>
            <div id="chartContainer" class="chart-wrap"></div>
        </div>
    </div>

    <div id="deleteModal" class="modal" role="dialog" aria-modal="true">
        <div class="modal-content" style="max-width: 520px;">
            <button class="modal-close" aria-label="Close" onclick="closeDeleteModal()">❌</button>
            <h3>Delete website</h3>
            <p class="modal-lead" id="deleteMessage"></p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="site_id" id="deleteSiteId">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" name="delete_site" class="btn-danger">Delete site</button>
                </div>
            </form>
        </div>
    </div>

    <div id="settingsModal" class="modal" role="dialog" aria-modal="true">
        <div class="modal-content" style="max-width: 520px;">
            <button class="modal-close" aria-label="Close" onclick="closeSettingsModal()">❌</button>
            <h3 id="settingsTitle">Site Settings</h3>
            <form method="POST" id="settingsForm" class="stack">
                <input type="hidden" name="site_id" id="settingsSiteId">
                <label for="settingsTimeout">Request Timeout (seconds)</label>
                <input type="number" id="settingsTimeout" name="timeout_seconds" min="1" required>
                <label for="settingsRetries">Number of Retries</label>
                <input type="number" id="settingsRetries" name="retries" min="0" required>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeSettingsModal()">Cancel</button>
                    <button type="submit" name="update_site_settings" class="btn-primary">Save Settings</button>
                </div>
            </form>
        </div>
    </div>

</body>
</html>

<?php
function sendTelegramMessage($botToken, $chatId, $message) {
    if ($botToken === '' || $chatId === '') {
        return [
            'ok' => false,
            'http_code' => 0,
            'response' => '',
            'description' => 'Telegram token and chat id are required.',
        ];
    }

    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response ?: '', true);

    return [
        'ok' => $curlError === '' && is_array($decoded) && !empty($decoded['ok']),
        'http_code' => $httpCode,
        'response' => $response ?: '',
        'description' => $curlError !== '' ? $curlError : (is_array($decoded) && isset($decoded['description']) ? $decoded['description'] : ''),
    ];
}

function verifyTelegramSettings($botToken, $chatId) {
    $botInfo = telegramApiRequest($botToken, 'getMe');
    if (!$botInfo['ok']) {
        return $botInfo;
    }

    $chatInfo = telegramApiRequest($botToken, 'getChat', ['chat_id' => $chatId]);
    if (!$chatInfo['ok']) {
        return $chatInfo;
    }

    return [
        'ok' => true,
        'http_code' => 200,
        'response' => '',
        'description' => '',
    ];
}

function telegramApiRequest($botToken, $method, array $payload = []) {
    $url = "https://api.telegram.org/bot{$botToken}/{$method}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    if (!empty($payload)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response ?: '', true);

    return [
        'ok' => $curlError === '' && is_array($decoded) && !empty($decoded['ok']),
        'http_code' => $httpCode,
        'response' => $response ?: '',
        'description' => $curlError !== '' ? $curlError : (is_array($decoded) && isset($decoded['description']) ? $decoded['description'] : ''),
    ];
}
?>