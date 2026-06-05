<?php
require_once __DIR__ . '/config.php';

$pdo = getDbConnection();
ensureSchema($pdo);

// Load Config from DB
$config = [];
foreach ($pdo->query("SELECT * FROM config") as $row) {
    $config[$row['setting_key']] = $row['setting_value'];
}

$quietStartHour = (int)($config['quiet_start_hour'] ?? $config['active_end_hour'] ?? 22);
$quietEndHour = (int)($config['quiet_end_hour'] ?? $config['active_start_hour'] ?? 8);
$defaultTimeout = (int)($config['default_timeout'] ?? 30);
$defaultRetries = (int)($config['default_retries'] ?? 2);
$notifierUrl = getNotifierUrl($config);

function isQuietTime(int $currentHour, int $quietStartHour, int $quietEndHour): bool {
    if ($quietStartHour === $quietEndHour) {
        return false;
    }

    if ($quietStartHour < $quietEndHour) {
        return $currentHour >= $quietStartHour && $currentHour < $quietEndHour;
    }

    return $currentHour >= $quietStartHour || $currentHour < $quietEndHour;
}

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
    $lastCurlError = '';

    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
        $attemptCount++;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_USERAGENT      => 'UptimeMonitor/1.0',
            CURLOPT_FAILONERROR    => false,
        ]);

        $start   = microtime(true);
        curl_exec($ch);
        $elapsed   = (microtime(true) - $start) * 1000;
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($attempt === 0) {
            $firstAttemptTime = round($elapsed, 2);
        }

        $cumulativeTime  += $elapsed;
        $finalStatusCode  = $http_code;
        $lastCurlError    = $curlError;

        // HTTP code 0 with a curl network error means OUR server couldn't reach
        // anything — treat as inconclusive rather than a site outage.
        $isNetworkError = ($http_code === 0 && $curlErrno !== 0);
        $isSuccess      = (!$isNetworkError && $http_code > 0 && $http_code < 400);

        if ($isSuccess) {
            $allFailed = false;
            break;
        }

        // Don't bother retrying if it's our own network that's broken
        if ($isNetworkError) {
            break;
        }
    }

    // Determine health status
    $isNetworkError = ($finalStatusCode === 0 && $lastCurlError !== '');

    if ($isNetworkError) {
        // Our monitor lost connectivity — don't record as a site outage
        $healthStatus = 'unknown';
    } elseif ($attemptCount === 1 && $finalStatusCode > 0 && $finalStatusCode < 400) {
        $healthStatus = 'green';
    } elseif (!$allFailed) {
        $healthStatus = 'yellow';
    } else {
        $healthStatus = 'red';
    }

    return [
        'status_code'    => $finalStatusCode,
        'response_time'  => $firstAttemptTime,
        'cumulative_time' => round($cumulativeTime, 2),
        'total_attempts' => $attemptCount,
        'health_status'  => $healthStatus,
        'curl_error'     => $lastCurlError,
    ];
}

function sendTelegram($msg, $config) {
    if (empty($config['telegram_bot_token']) || empty($config['telegram_chat_id'])) {
        return false;
    }

    $url = "https://api.telegram.org/bot{$config['telegram_bot_token']}/sendMessage";
    $data = [
        'chat_id' => $config['telegram_chat_id'],
        'text' => $msg,
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

    if ($curlError !== '') {
        error_log('Telegram send failed: ' . $curlError);
        return false;
    }

    $decoded = json_decode($response ?: '', true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        error_log('Telegram send failed: ' . ($response ?: 'no response'));
        return false;
    }

    return $httpCode >= 200 && $httpCode < 300;
}

function calculateUptimeMetrics($pdo, $siteId) {
    $pastDay = date('Y-m-d H:i:s', time() - 86400);
    
    // Get all logs from the past 24 hours
    $stmt = $pdo->prepare(
        "SELECT health_status FROM logs WHERE site_id = ? AND checked_at > ? ORDER BY checked_at ASC"
    );
    $stmt->execute([$siteId, $pastDay]);
    $logs = $stmt->fetchAll();

    if (empty($logs)) {
        return ['uptime' => 0, 'outages' => 0];
    }

    $totalChecks = count($logs);
    $successfulChecks = 0;
    $outageCount = 0;
    $inOutage = false;

    foreach ($logs as $log) {
        foreach ($logs as $log) {
        if ($log['health_status'] === 'unknown') {
            $totalChecks--; // Don't count inconclusive checks at all
            continue;
        }
        if ($log['health_status'] === 'green') {
            $successfulChecks++;
            if ($inOutage) {
                $inOutage = false;
            }
        } elseif ($log['health_status'] === 'red') {
            if (!$inOutage) {
                $outageCount++;
                $inOutage = true;
            }
        } elseif ($log['health_status'] === 'yellow') {
            $successfulChecks++;
        }
    }
}

    $uptime = $totalChecks > 0 ? round(($successfulChecks / $totalChecks) * 100, 2) : 0;

    return ['uptime' => $uptime, 'outages' => $outageCount];
}

function buildMorningDigestMessage(array $site, array $metrics, array $result, string $notifierUrl = ''): string {
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
    $message .= buildTelegramNotifierSuffix($notifierUrl);

    return $message;
}

function sendMorningDigest(PDO $pdo, array $site, array $result, array $config, string $today): bool {
    // Compute recent metrics to build the message and for the "only on issue" check
    $metrics = calculateUptimeMetrics($pdo, $site['id']);

    // Honor per-site "only send when uptime < 100%" flag
    $onlyOnIssue = (int)($site['morning_summary_only_on_issue'] ?? 0) === 1;
    if ($onlyOnIssue && isset($metrics['uptime']) && (float)$metrics['uptime'] >= 100.0) {
        // Skip sending and do NOT update last_morning_summary_sent so that the
        // next scheduled period can still attempt to send if an issue appears.
        return false;
    }

    $message = buildMorningDigestMessage($site, $metrics, $result, getNotifierUrl($config));

    if (!sendTelegram($message, $config)) {
        return false;
    }

    // Only mark as sent after successful delivery
    $pdo->prepare("UPDATE sites SET pending_alert = 0, last_morning_summary_sent = ? WHERE id = ?")
        ->execute([$today, $site['id']]);

    return true;
}

// Set Timezone and check Quiet Time
date_default_timezone_set($config['timezone'] ?? 'UTC');
$currentHour = (int)date('H');
$today = date('Y-m-d');
$activeStartHour = (int)($config['active_start_hour'] ?? $quietEndHour ?? 8);
$isQuietTime = isQuietTime($currentHour, $quietStartHour, $quietEndHour);

$sites = $pdo->query("SELECT * FROM sites WHERE is_active = 1")->fetchAll();

foreach ($sites as $site) {
    $timeout = (int)($site['timeout_seconds'] ?? $defaultTimeout);
    $retries = (int)($site['retries'] ?? $defaultRetries);
    $summaryEnabled = (int)($site['morning_summary_enabled'] ?? 0) === 1;
    
    // Perform ping with retries
    $result = pingWithRetries($site['url'], $timeout, $retries);
    
    // Log the result
    $stmt = $pdo->prepare(
        "INSERT INTO logs (site_id, status_code, response_time, cumulative_time, total_attempts, health_status) 
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $site['id'],
        $result['status_code'],
        $result['response_time'],
        $result['cumulative_time'],
        $result['total_attempts'],
        $result['health_status']
    ]);

    // Update site's current status
    $pdo->prepare("UPDATE sites SET last_status = ?, last_health_status = ? WHERE id = ?")
        ->execute([$result['status_code'], $result['health_status'], $site['id']]);

    // After pingWithRetries call...
    $isDown = ($result['health_status'] === 'red');
    $isInconclusive = ($result['health_status'] === 'unknown');

    // Skip everything if our own network is the problem
    if ($isInconclusive) {
        error_log("Monitor network error for {$site['url']}: {$result['curl_error']}");
        continue; // Don't log, don't alert, don't update status
    }

    // Determine if this site's summary period is due based on preferred frequency
    $frequency = strtolower(trim((string)($site['morning_summary_frequency'] ?? $config['morning_summary_frequency'] ?? 'daily')));
    $lastSent = $site['last_morning_summary_sent'] ?? '';
    $periodDue = false;
    if (empty($lastSent)) {
        $periodDue = true;
    } else {
        $daysSince = (int)floor((strtotime($today) - strtotime($lastSent)) / 86400);
        if ($frequency === 'weekly') {
            $periodDue = $daysSince >= 7;
        } elseif ($frequency === 'monthly') {
            $periodDue = $daysSince >= 30;
        } else {
            // daily (default)
            $periodDue = ($lastSent !== $today);
        }
    }

    $needsMorningDigest = $summaryEnabled && !$isQuietTime && $currentHour >= $activeStartHour && $periodDue;
    $morningDigestSent = false;
    
    if ($result['health_status'] === 'green') {
        $alertEmoji = '✅';
    } elseif ($result['health_status'] === 'yellow') {
        $alertEmoji = '🟨';
    } elseif ($result['health_status'] === 'red') {
        $alertEmoji = '🚨';
    } else {
        $alertEmoji = '❓';
    }

    if ($needsMorningDigest) {
        $morningDigestSent = sendMorningDigest($pdo, $site, $result, $config, $today);
    }

    if ($isDown) {
        if ($isQuietTime) {
            // Flag for later notification
            $pdo->prepare("UPDATE sites SET pending_alert = 1 WHERE id = ?")
                ->execute([$site['id']]);
        } elseif (!$morningDigestSent) {
            // Send Alert Immediately
            $attemptsInfo = $result['total_attempts'] > 1 
                ? " (failed after {$result['total_attempts']} attempts)" 
                : '';
            sendTelegram(
                "$alertEmoji ALERT: {$site['alias']} ({$site['url']}) is DOWN. Status: {$result['status_code']}{$attemptsInfo}" . buildTelegramNotifierSuffix($notifierUrl),
                $config
            );
            $pdo->prepare("UPDATE sites SET pending_alert = 0 WHERE id = ?")
                ->execute([$site['id']]);
        }
    } elseif ($result['health_status'] === 'yellow') {
        // Yellow status - recovered after retries
        if (!$isQuietTime && !$morningDigestSent) {
            sendTelegram(
                "$alertEmoji RECOVERED: {$site['alias']} recovered after {$result['total_attempts']} attempts. Response Time: {$result['cumulative_time']}ms" . buildTelegramNotifierSuffix($notifierUrl),
                $config
            );
        }
    }
    // Green status - no alert needed
}