<?php
require_once __DIR__ . '/config.php';

$pdo = getDbConnection();

// Load Config from DB
$config = [];
foreach ($pdo->query("SELECT * FROM config") as $row) {
    $config[$row['setting_key']] = $row['setting_value'];
}

$quietStartHour = (int)($config['quiet_start_hour'] ?? $config['active_end_hour'] ?? 22);
$quietEndHour = (int)($config['quiet_end_hour'] ?? $config['active_start_hour'] ?? 8);

function isQuietTime(int $currentHour, int $quietStartHour, int $quietEndHour): bool {
    if ($quietStartHour === $quietEndHour) {
        return false;
    }

    if ($quietStartHour < $quietEndHour) {
        return $currentHour >= $quietStartHour && $currentHour < $quietEndHour;
    }

    return $currentHour >= $quietStartHour || $currentHour < $quietEndHour;
}

// Set Timezone and check Quiet Time
date_default_timezone_set($config['timezone'] ?? 'UTC');
$currentHour = (int)date('H');
$isQuietTime = isQuietTime($currentHour, $quietStartHour, $quietEndHour);

$sites = $pdo->query("SELECT * FROM sites WHERE is_active = 1")->fetchAll();

foreach ($sites as $site) {
    $ch = curl_init($site['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'UptimeMonitor/1.0'
    ]);
    
    $start = microtime(true);
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $time = round((microtime(true) - $start) * 1000, 2);
    curl_close($ch);

    // Log the ping
    $stmt = $pdo->prepare("INSERT INTO logs (site_id, status_code, response_time) VALUES (?, ?, ?)");
    $stmt->execute([$site['id'], $http_code, $time]);

    $isDown = ($http_code == 0 || $http_code >= 400);

    if ($isDown) {
        if ($isQuietTime) {
            // Flag for later notification
            $pdo->prepare("UPDATE sites SET last_status = ?, pending_alert = 1 WHERE id = ?")
                ->execute([$http_code, $site['id']]);
        } else {
            // Send Alert Immediately
            sendTelegram("🚨 ALERT: {$site['alias']} ({$site['url']}) is DOWN. Status: $http_code", $config);
            $pdo->prepare("UPDATE sites SET last_status = ?, pending_alert = 0 WHERE id = ?")
                ->execute([$http_code, $site['id']]);
        }
    } else {
        // Site is Up
        $pdo->prepare("UPDATE sites SET last_status = ?, pending_alert = 0 WHERE id = ?")
            ->execute([$http_code, $site['id']]);
    }
}

// Catch-up logic: If we just entered active hours, send pending alerts
if (!$isQuietTime) {
    $pending = $pdo->query("SELECT * FROM sites WHERE pending_alert = 1")->fetchAll();
    foreach ($pending as $site) {
        sendTelegram("🌅 Morning Catch-up: {$site['alias']} is still DOWN (Status: {$site['last_status']})", $config);
        $pdo->prepare("UPDATE sites SET pending_alert = 0 WHERE id = ?")->execute([$site['id']]);
    }
}

function sendTelegram($msg, $config) {
    if (empty($config['telegram_bot_token']) || empty($config['telegram_chat_id'])) return;
    $url = "https://api.telegram.org/bot{$config['telegram_bot_token']}/sendMessage";
    $data = ['chat_id' => $config['telegram_chat_id'], 'text' => $msg];
    
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];
    @file_get_contents($url, false, stream_context_create($options));
}