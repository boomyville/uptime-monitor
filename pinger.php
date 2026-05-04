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

function sendTelegram($msg, $config) {
    if (empty($config['telegram_bot_token']) || empty($config['telegram_chat_id'])) return;

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

    $uptime = $totalChecks > 0 ? round(($successfulChecks / $totalChecks) * 100, 2) : 0;

    return ['uptime' => $uptime, 'outages' => $outageCount];
}

// Set Timezone and check Quiet Time
date_default_timezone_set($config['timezone'] ?? 'UTC');
$currentHour = (int)date('H');
$isQuietTime = isQuietTime($currentHour, $quietStartHour, $quietEndHour);

$sites = $pdo->query("SELECT * FROM sites WHERE is_active = 1")->fetchAll();

foreach ($sites as $site) {
    $timeout = (int)($site['timeout_seconds'] ?? $defaultTimeout);
    $retries = (int)($site['retries'] ?? $defaultRetries);
    
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

    // Determine if we should alert
    $isDown = ($result['health_status'] === 'red');
    
    if ($result['health_status'] === 'green') {
        $alertEmoji = '✅';
    } elseif ($result['health_status'] === 'yellow') {
        $alertEmoji = '🟨';
    } elseif ($result['health_status'] === 'red') {
        $alertEmoji = '🚨';
    } else {
        $alertEmoji = '❓';
    }

    if ($isDown) {
        if ($isQuietTime) {
            // Flag for later notification
            $pdo->prepare("UPDATE sites SET pending_alert = 1 WHERE id = ?")
                ->execute([$site['id']]);
        } else {
            // Send Alert Immediately
            $attemptsInfo = $result['total_attempts'] > 1 
                ? " (failed after {$result['total_attempts']} attempts)" 
                : '';
            sendTelegram(
                "$alertEmoji ALERT: {$site['alias']} ({$site['url']}) is DOWN. Status: {$result['status_code']}{$attemptsInfo}",
                $config
            );
            $pdo->prepare("UPDATE sites SET pending_alert = 0 WHERE id = ?")
                ->execute([$site['id']]);
        }
    } elseif ($result['health_status'] === 'yellow') {
        // Yellow status - recovered after retries
        if (!$isQuietTime) {
            sendTelegram(
                "$alertEmoji RECOVERED: {$site['alias']} recovered after {$result['total_attempts']} attempts. Response Time: {$result['cumulative_time']}ms",
                $config
            );
        }
    }
    // Green status - no alert needed
}

// Catch-up logic: If we just entered active hours, send pending alerts
if (!$isQuietTime) {
    $pending = $pdo->query("SELECT * FROM sites WHERE pending_alert = 1")->fetchAll();
    foreach ($pending as $site) {
        $metrics = calculateUptimeMetrics($pdo, $site['id']);
        $uptimePercent = $metrics['uptime'];
        $outageCount = $metrics['outages'];
        $message = "🌅 Morning Catch-up: {$site['alias']} is still DOWN (Status: {$site['last_status']})\n";
        $message .= "📊 Last 24h: {$uptimePercent}% uptime | {$outageCount} outage" . ($outageCount !== 1 ? 's' : '');
        sendTelegram($message, $config);
        $pdo->prepare("UPDATE sites SET pending_alert = 0 WHERE id = ?")->execute([$site['id']]);
    }
}