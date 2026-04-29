<?php
session_start();
require_once 'config.php';
$pdo = getDbConnection();

// --- Logic Helpers ---

function pingSite($pdo, $id, $url, $alias) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $start = microtime(true);
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $time = round((microtime(true) - $start) * 1000, 2);
    curl_close($ch);

    $pdo->prepare("INSERT INTO logs (site_id, status_code, response_time) VALUES (?, ?, ?)")->execute([$id, $http_code, $time]);
    $pdo->prepare("UPDATE sites SET last_status = ? WHERE id = ?")->execute([$http_code, $id]);
}

// --- Actions ---

if (isset($_POST['delete_site'])) {
    $siteId = (int)$_POST['site_id'];
    $pdo->prepare("DELETE FROM sites WHERE id = ?")->execute([$siteId]);
    header('Location: index.php');
    exit;
}

if (isset($_POST['ping_site'])) {
    $siteId = (int)$_POST['site_id'];
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $site = $stmt->fetch();

    if ($site) {
        pingSite($pdo, $site['id'], $site['url'], $site['alias']);
    }

    header('Location: index.php');
    exit;
}

if (isset($_POST['add_site'])) {
    $stmt = $pdo->prepare("INSERT INTO sites (url, alias) VALUES (?, ?)");
    $stmt->execute([$_POST['url'], $_POST['alias']]);
    $newId = $pdo->lastInsertId();
    pingSite($pdo, $newId, $_POST['url'], $_POST['alias']); // Instant check
}

if (isset($_POST['clear_logs'])) {
    $days = (int)$_POST['log_days'];
    if ($days === 0) {
        $pdo->query("TRUNCATE TABLE logs");
    } else {
        $pdo->prepare("DELETE FROM logs WHERE checked_at < DATE_SUB(NOW(), INTERVAL ? DAY)")->execute([$days]);
    }
}

if (isset($_POST['save_settings'])) {
    $settings = [
        'quiet_start_hour' => (int)$_POST['quiet_start_hour'],
        'quiet_end_hour' => (int)$_POST['quiet_end_hour'],
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

    header('Location: index.php');
    exit;
}

// JSON Endpoint for Chart Data
if (isset($_GET['get_stats'])) {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("SELECT response_time, checked_at FROM logs WHERE site_id = ? ORDER BY checked_at DESC LIMIT 50");
    $stmt->execute([$_GET['get_stats']]);
    echo json_encode(array_reverse($stmt->fetchAll()));
    exit;
}

// ... (Rest of login/setup logic from previous version) ...

$sites = $pdo->query("SELECT * FROM sites")->fetchAll();
$config = $pdo->query("SELECT setting_key, setting_value FROM config")->fetchAll(PDO::FETCH_KEY_PAIR);
$quietStartHour = (int)($config['quiet_start_hour'] ?? $config['active_end_hour'] ?? 22);
$quietEndHour = (int)($config['quiet_end_hour'] ?? $config['active_start_hour'] ?? 8);
$telegramBotToken = $config['telegram_bot_token'] ?? '';
$telegramChatId = $config['telegram_chat_id'] ?? '';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Uptime Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        :root { color-scheme: light; }
        body { font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 0; color: #0f172a; background: radial-gradient(circle at top, #eef2ff 0%, #f8fafc 32%, #e2e8f0 100%); }
        .container { max-width: 1120px; margin: 0 auto; padding: 32px 20px 48px; }
        .hero { margin-bottom: 24px; }
        .hero h1 { margin: 0 0 6px; font-size: clamp(2rem, 3vw, 3rem); letter-spacing: -0.04em; }
        .hero p { margin: 0; color: #475569; }
        .card { background: rgba(255, 255, 255, 0.88); backdrop-filter: blur(10px); border: 1px solid rgba(148, 163, 184, 0.18); padding: 22px; border-radius: 20px; box-shadow: 0 18px 45px rgba(15, 23, 42, 0.08); margin-bottom: 22px; }
        table { width: 100%; border-collapse: collapse; }
        th { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; text-align: left; padding: 0 12px 12px; }
        td { padding: 14px 12px; border-top: 1px solid #e2e8f0; vertical-align: middle; }
        .status { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; font-size: 0.8rem; font-weight: 700; }
        .up { background: #dcfce7; color: #166534; }
        .down { background: #fee2e2; color: #991b1b; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        button, .btn-link { border: none; border-radius: 999px; padding: 10px 14px; font-weight: 700; cursor: pointer; transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease; }
        button:hover, .btn-link:hover { transform: translateY(-1px); }
        .btn-primary { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: white; box-shadow: 0 10px 24px rgba(37, 99, 235, 0.24); }
        .btn-secondary { background: #e2e8f0; color: #0f172a; }
        .btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; box-shadow: 0 10px 24px rgba(220, 38, 38, 0.18); }
        .btn-mini { padding: 9px 12px; font-size: 0.9rem; }
        form.inline { display: inline; }
        input, select { border: 1px solid #cbd5e1; border-radius: 12px; padding: 11px 12px; font-size: 1rem; background: white; }
        form.stack { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .empty-state { color: #64748b; }
        .chart-wrap { height: 360px; }
        .modal { display: none; position: fixed; inset: 0; z-index: 1000; background: rgba(15, 23, 42, 0.62); align-items: center; justify-content: center; padding: 18px; }
        .modal.is-open { display: flex; }
        .modal-content { width: min(920px, 100%); background: white; border-radius: 24px; padding: 22px; box-shadow: 0 28px 70px rgba(15, 23, 42, 0.3); position: relative; }
        .modal-close { position: absolute; right: 14px; top: 14px; width: 38px; height: 38px; border-radius: 999px; background: #fee2e2; color: #b91c1c; border: none; font-size: 20px; font-weight: 800; cursor: pointer; }
        .modal-close:hover { background: #fecaca; }
        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; }
        .modal-lead { margin: 0 0 10px; color: #475569; }
    </style>
</head>
<body>
    <div class="container">
        <div class="hero">
            <h1>Uptime Dashboard</h1>
            <p>Track checks, inspect trends, ping on demand, and clean up sites without leaving the page.</p>
        </div>

        <div class="card">
            <h2>Current Status</h2>
            <table>
                <tr><th>Alias</th><th>Status</th><th>Stats</th><th>Actions</th></tr>
                <?php foreach ($sites as $s): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($s['alias']) ?></strong></td>
                    <td><span class="status <?= ($s['last_status'] < 400 && $s['last_status'] > 0) ? 'up' : 'down' ?>"><?= $s['last_status'] ?></span></td>
                    <td><button type="button" class="btn-secondary btn-mini" onclick="showChart(<?= (int)$s['id'] ?>, <?= htmlspecialchars(json_encode($s['alias']), ENT_QUOTES, 'UTF-8') ?>)">View Graph</button></td>
                    <td>
                        <div class="actions">
                            <form method="POST" class="inline">
                                <input type="hidden" name="site_id" value="<?= $s['id'] ?>">
                                <button type="submit" name="ping_site" class="btn-primary btn-mini">Ping Now</button>
                            </form>
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
        </div>

        <div class="card">
            <h3>Alert Settings</h3>
            <p class="modal-lead">Set quiet time hours and Telegram credentials for downtime alerts.</p>
            <form method="POST" class="stack">
                <label for="quiet_start_hour">Quiet Time Start</label>
                <select name="quiet_start_hour" id="quiet_start_hour">
                    <?php for ($hour = 0; $hour < 24; $hour++): ?>
                        <option value="<?= $hour ?>" <?= $quietStartHour === $hour ? 'selected' : '' ?>><?= sprintf('%02d:00', $hour) ?></option>
                    <?php endfor; ?>
                </select>

                <label for="quiet_end_hour">Quiet Time End</label>
                <select name="quiet_end_hour" id="quiet_end_hour">
                    <?php for ($hour = 0; $hour < 24; $hour++): ?>
                        <option value="<?= $hour ?>" <?= $quietEndHour === $hour ? 'selected' : '' ?>><?= sprintf('%02d:00', $hour) ?></option>
                    <?php endfor; ?>
                </select>

                <input type="text" name="telegram_bot_token" placeholder="Telegram bot token" value="<?= htmlspecialchars($telegramBotToken) ?>">
                <input type="text" name="telegram_chat_id" placeholder="Telegram chat id" value="<?= htmlspecialchars($telegramChatId) ?>">
                <button type="submit" name="save_settings" class="btn-primary">Save Alert Settings</button>
            </form>
        </div>
    </div>

    <div id="chartModal" class="modal" role="dialog" aria-modal="true">
        <div class="modal-content">
            <button class="modal-close" aria-label="Close" onclick="closeChart()">❌</button>
            <h3 id="modalTitle"></h3>
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

    <script>
    let myChart;
    function showChart(id, name) {
        const modal = document.getElementById('chartModal');
        document.getElementById('modalTitle').innerText = "Response Time (ms) - " + name;
        fetch('index.php?get_stats=' + id)
            .then(res => res.json())
            .then(data => {
                const labels = data.map(i => new Date(i.checked_at).toLocaleString());
                const values = data.map(i => Number(i.response_time));

                if (myChart) myChart.destroy();
                document.getElementById('chartContainer').innerHTML = '';
                myChart = new ApexCharts(document.querySelector('#chartContainer'), {
                    chart: {
                        type: 'line',
                        height: 360,
                        toolbar: { show: false },
                        fontFamily: 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
                    },
                    series: [{ name: 'Response time (ms)', data: values }],
                    stroke: { curve: 'smooth', width: 3 },
                    colors: ['#2563eb'],
                    grid: { borderColor: '#e2e8f0' },
                    xaxis: { categories: labels, labels: { rotate: -45, hideOverlappingLabels: true } },
                    yaxis: { labels: { formatter: (value) => Math.round(value) } },
                    tooltip: { y: { formatter: (value) => `${value} ms` } }
                });
                myChart.render();
                modal.classList.add('is-open');
            });
    }

    function closeChart() {
        const modal = document.getElementById('chartModal');
        modal.classList.remove('is-open');
        if (myChart) { myChart.destroy(); myChart = null; }
    }

    function openDeleteModal(id, name) {
        document.getElementById('deleteSiteId').value = id;
        document.getElementById('deleteMessage').innerText = `This will permanently delete ${name} and all of its logs.`;
        document.getElementById('deleteModal').classList.add('is-open');
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('is-open');
    }

    document.getElementById('chartModal').addEventListener('click', function(e) {
        if (e.target.id === 'chartModal') closeChart();
    });
    document.getElementById('deleteModal').addEventListener('click', function(e) {
        if (e.target.id === 'deleteModal') closeDeleteModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeChart();
            closeDeleteModal();
        }
    });
    </script>
</body>
</html>