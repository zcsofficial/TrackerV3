<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/partials/layout.php';
require_login();
$user = current_user();
if ($user['role'] !== 'superadmin' && $user['role'] !== 'admin') {
	http_response_code(403);
	echo 'Forbidden';
	exit;
}
$pdo = db();

// Load current setting
$stmt = $pdo->prepare('SELECT `key`, `value` FROM settings WHERE `key` IN ("productive_hours_per_day_seconds","agent_sync_interval_seconds","parallel_sync_workers","delete_screenshots_after_sync","agent_install_path","device_monitoring_enabled","screenshots_enabled","screenshot_interval_seconds")');
$stmt->execute();
$kv = [];
foreach ($stmt->fetchAll() as $r) { $kv[$r['key']] = $r['value']; }
$seconds = (int)($kv['productive_hours_per_day_seconds'] ?? 28800);
$hours = $seconds > 0 ? $seconds / 3600 : 8;
$syncInterval = (int)($kv['agent_sync_interval_seconds'] ?? 60);
$parallelWorkers = (int)($kv['parallel_sync_workers'] ?? 1);
$deleteScreenshots = (int)($kv['delete_screenshots_after_sync'] ?? 1);
$installPath = $kv['agent_install_path'] ?? '';
$deviceMonitoring = (int)($kv['device_monitoring_enabled'] ?? 0);
$screenshotsEnabled = (int)($kv['screenshots_enabled'] ?? 1);
$screenshotInterval = (int)($kv['screenshot_interval_seconds'] ?? 300);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $h = (float)($_POST['productive_hours'] ?? 8);
	if ($h < 0) { $h = 0; }
	$sec = (int)round($h * 3600);
    $up = $pdo->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
    $up->execute(['productive_hours_per_day_seconds', (string)$sec]);

    $interval = (int)($_POST['agent_sync_interval_seconds'] ?? 60);
    if ($interval < 15) { $interval = 15; }
    $up->execute(['agent_sync_interval_seconds', (string)$interval]);

    $parallel = (int)($_POST['parallel_sync_workers'] ?? 1);
    if ($parallel < 1) { $parallel = 1; }
    if ($parallel > 10) { $parallel = 10; }
    $up->execute(['parallel_sync_workers', (string)$parallel]);

    $deleteScreenshots = isset($_POST['delete_screenshots_after_sync']) ? 1 : 0;
    $up->execute(['delete_screenshots_after_sync', (string)$deleteScreenshots]);

    $installPath = trim($_POST['agent_install_path'] ?? '');
    $up->execute(['agent_install_path', $installPath]);

    $deviceMonitoring = isset($_POST['device_monitoring_enabled']) ? 1 : 0;
    $up->execute(['device_monitoring_enabled', (string)$deviceMonitoring]);

    $screenshotsEnabled = isset($_POST['screenshots_enabled']) ? 1 : 0;
    $up->execute(['screenshots_enabled', (string)$screenshotsEnabled]);

    $screenshotInterval = (int)($_POST['screenshot_interval_seconds'] ?? 300);
    if ($screenshotInterval < 60) { $screenshotInterval = 60; }  // Minimum 60 seconds
    $up->execute(['screenshot_interval_seconds', (string)$screenshotInterval]);
    
	header('Location: ' . BASE_URL . 'settings.php?saved=1');
	exit;
}

render_layout('Settings', function() use ($hours, $syncInterval, $parallelWorkers, $deleteScreenshots, $installPath, $deviceMonitoring, $screenshotsEnabled, $screenshotInterval) { ?>
    <h5>Settings</h5>
    <?php if (!empty($_GET['saved'])): ?>
    <div class="alert alert-success">Saved.</div>
    <?php endif; ?>
    <form method="post" class="mt-3" style="max-width:420px;">
        <label class="form-label">Productive hours per day</label>
        <div class="input-group">
            <input type="number" class="form-control" name="productive_hours" step="0.5" min="0" value="<?php echo htmlspecialchars((string)$hours); ?>">
            <span class="input-group-text">hours</span>
        </div>
        <div class="mt-3">
            <label class="form-label">Agent sync interval</label>
            <div class="input-group">
                <input type="number" class="form-control" name="agent_sync_interval_seconds" min="15" step="15" value="<?php echo htmlspecialchars((string)$syncInterval); ?>">
                <span class="input-group-text">seconds</span>
            </div>
            <div class="form-text">Minimum 15 seconds.</div>
        </div>
        <div class="mt-3">
            <label class="form-label">Parallel sync workers</label>
            <div class="input-group">
                <input type="number" class="form-control" name="parallel_sync_workers" min="1" max="10" step="1" value="<?php echo htmlspecialchars((string)$parallelWorkers); ?>">
                <span class="input-group-text">workers</span>
            </div>
            <div class="form-text">Number of parallel sync threads (1-10). Higher values may improve sync speed for large amounts of data.</div>
        </div>
        <div class="mt-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="screenshots_enabled" id="screenshotsEnabled" value="1" <?php echo $screenshotsEnabled ? 'checked' : ''; ?>>
                <label class="form-check-label" for="screenshotsEnabled">
                    Enable screenshots
                </label>
            </div>
            <div class="form-text">When enabled, agents will capture screenshots at the configured interval.</div>
        </div>
        <div class="mt-3">
            <label class="form-label">Screenshot interval</label>
            <div class="input-group">
                <input type="number" class="form-control" name="screenshot_interval_seconds" min="60" step="60" value="<?php echo htmlspecialchars((string)$screenshotInterval); ?>">
                <span class="input-group-text">seconds</span>
            </div>
            <div class="form-text">Interval between screenshots (minimum 60 seconds / 1 minute).</div>
            <div class="form-text small text-muted">Current: <?php echo round($screenshotInterval / 60, 1); ?> minutes</div>
        </div>
        <div class="mt-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="delete_screenshots_after_sync" id="deleteScreenshots" value="1" <?php echo $deleteScreenshots ? 'checked' : ''; ?>>
                <label class="form-check-label" for="deleteScreenshots">
                    Delete screenshots from agent after successful sync
                </label>
            </div>
            <div class="form-text">When enabled, screenshot files are deleted from the agent's local storage after successful upload to server.</div>
        </div>
        <div class="mt-3">
            <label class="form-label">Agent Install Path</label>
            <input type="text" class="form-control" name="agent_install_path" value="<?php echo htmlspecialchars($installPath); ?>" placeholder="Leave empty for default (ProgramData\TrackerV3Agent)">
            <div class="form-text">Default installation directory for agents. Leave empty to use default: C:\ProgramData\TrackerV3Agent on Windows.</div>
        </div>
        <div class="mt-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="device_monitoring_enabled" id="deviceMonitoring" value="1" <?php echo $deviceMonitoring ? 'checked' : ''; ?>>
                <label class="form-check-label" for="deviceMonitoring">
                    Enable device monitoring globally
                </label>
            </div>
            <div class="form-text">When enabled, agents can monitor external device connections. Individual agents can still have monitoring enabled/disabled per-machine in the Devices page.</div>
        </div>
        <button class="btn btn-primary mt-3" type="submit">Save</button>
    </form>
<?php });


