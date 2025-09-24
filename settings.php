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
$stmt = $pdo->prepare('SELECT `key`, `value` FROM settings WHERE `key` IN ("productive_hours_per_day_seconds","agent_sync_interval_seconds")');
$stmt->execute();
$kv = [];
foreach ($stmt->fetchAll() as $r) { $kv[$r['key']] = $r['value']; }
$seconds = (int)($kv['productive_hours_per_day_seconds'] ?? 28800);
$hours = $seconds > 0 ? $seconds / 3600 : 8;
$syncInterval = (int)($kv['agent_sync_interval_seconds'] ?? 60);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $h = (float)($_POST['productive_hours'] ?? 8);
	if ($h < 0) { $h = 0; }
	$sec = (int)round($h * 3600);
    $up = $pdo->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
    $up->execute(['productive_hours_per_day_seconds', (string)$sec]);

    $interval = (int)($_POST['agent_sync_interval_seconds'] ?? 60);
    if ($interval < 15) { $interval = 15; }
    $up->execute(['agent_sync_interval_seconds', (string)$interval]);
	header('Location: ' . BASE_URL . 'settings.php?saved=1');
	exit;
}

render_layout('Settings', function() use ($hours, $syncInterval) { ?>
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
        <button class="btn btn-primary mt-3" type="submit">Save</button>
    </form>
<?php });


