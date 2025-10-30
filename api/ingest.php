<?php
// Endpoint for agent to send activity and screenshots
// POST JSON:
// {
//   "username": "john",
//   "machine_id": "WIN-ABC123",
//   "hostname": "MYPC",
//   "activity": [ { start_time, end_time, productive_seconds, unproductive_seconds, idle_seconds, mouse_moves, key_presses }... ],
//   "screenshots": [ { taken_at, filename, data_base64 } ... ],
//   "application_usage": [ { application_name, process_name, window_title, executable_path, session_start, session_end, duration_seconds, is_productive } ... ]
// }
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$raw = file_get_contents('php://input');
$json = json_decode($raw, true);
if (!$json) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); exit; }

$pdo = db();

$username = trim($json['username'] ?? '');
$machineExtId = trim($json['machine_id'] ?? '');
$hostname = trim($json['hostname'] ?? '');

if ($username === '' || $machineExtId === '') { http_response_code(400); echo json_encode(['error'=>'Missing username or machine_id']); exit; }

$userStmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$userStmt->execute([$username]);
$user = $userStmt->fetch();
if (!$user) {
    // Auto-register user on first agent contact as employee with random password
    $randPass = bin2hex(random_bytes(12));
    $hash = password_hash($randPass, PASSWORD_DEFAULT);
    $insU = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, "employee")');
    $insU->execute([$username, $hash]);
    $user = ['id' => (int)$pdo->lastInsertId()];
}

// Upsert machine
$machineStmt = $pdo->prepare('SELECT id FROM machines WHERE machine_id = ?');
$machineStmt->execute([$machineExtId]);
$machine = $machineStmt->fetch();
if ($machine) {
	$machineId = (int)$machine['id'];
	$upd = $pdo->prepare('UPDATE machines SET user_id = ?, hostname = ?, last_seen = NOW() WHERE id = ?');
	$upd->execute([(int)$user['id'], $hostname, $machineId]);
} else {
	$ins = $pdo->prepare('INSERT INTO machines (machine_id, user_id, hostname, last_seen) VALUES (?, ?, ?, NOW())');
	$ins->execute([$machineExtId, (int)$user['id'], $hostname]);
	$machineId = (int)$pdo->lastInsertId();
}

$pdo->beginTransaction();
try {
	$actIns = $pdo->prepare('INSERT INTO activity (user_id, machine_id, start_time, end_time, productive_seconds, unproductive_seconds, idle_seconds, mouse_moves, key_presses) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
	foreach (($json['activity'] ?? []) as $a) {
		$actIns->execute([
			(int)$user['id'],
			$machineId,
			$a['start_time'] ?? date('Y-m-d H:i:s'),
			$a['end_time'] ?? date('Y-m-d H:i:s'),
			(int)($a['productive_seconds'] ?? 0),
			(int)($a['unproductive_seconds'] ?? 0),
			(int)($a['idle_seconds'] ?? 0),
			(int)($a['mouse_moves'] ?? 0),
			(int)($a['key_presses'] ?? 0),
		]);
	}

	$shotIns = $pdo->prepare('INSERT INTO screenshots (user_id, machine_id, taken_at, filename, filesize_kb) VALUES (?, ?, ?, ?, ?)');
	foreach (($json['screenshots'] ?? []) as $s) {
		$fname = basename($s['filename'] ?? (uniqid('sc_', true) . '.jpg'));
		$dt = $s['taken_at'] ?? date('Y-m-d H:i:s');
		$data = $s['data_base64'] ?? '';
		if ($data !== '') {
			$binary = base64_decode($data);
			if (!is_dir(STORAGE_PATH)) { @mkdir(STORAGE_PATH, 0775, true); }
			$path = STORAGE_PATH . DIRECTORY_SEPARATOR . $fname;
			file_put_contents($path, $binary);
			$kb = (int)ceil(filesize($path) / 1024);
			$shotIns->execute([(int)$user['id'], $machineId, $dt, $fname, $kb]);
		}
	}

    // Application usage
    $appFind = $pdo->prepare('SELECT id FROM applications WHERE process_name = ? LIMIT 1');
    $appIns = $pdo->prepare('INSERT INTO applications (name, process_name, executable_path, first_seen, last_seen, total_sessions, total_usage_seconds) VALUES (?, ?, ?, ?, ?, 0, 0)');
    $appUpd = $pdo->prepare('UPDATE applications SET name = COALESCE(?, name), executable_path = COALESCE(?, executable_path), last_seen = ?, total_sessions = total_sessions + 1, total_usage_seconds = total_usage_seconds + ? WHERE id = ?');
    $usageIns = $pdo->prepare('INSERT INTO application_usage (user_id, machine_id, application_id, application_name, process_name, window_title, session_start, session_end, duration_seconds, is_productive) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $timelineIns = $pdo->prepare('INSERT INTO activity_timeline (user_id, machine_id, activity_type, application_id, item_name, item_detail, start_time, end_time, duration_seconds, is_productive) VALUES (?, ?, "application", ?, ?, ?, ?, ?, ?, ?)');
    foreach (($json['application_usage'] ?? []) as $u) {
        $appName = trim($u['application_name'] ?? '');
        $processName = trim($u['process_name'] ?? '');
        if ($processName === '') { continue; }
        $windowTitle = $u['window_title'] ?? null;
        $exePath = $u['executable_path'] ?? null;
        $start = $u['session_start'] ?? date('Y-m-d H:i:s');
        $end = $u['session_end'] ?? null;
        $dur = (int)($u['duration_seconds'] ?? 0);
        $isProd = isset($u['is_productive']) ? (int)$u['is_productive'] : null;

        // Upsert application catalog row
        $appId = null;
        $appFind->execute([$processName]);
        $found = $appFind->fetch();
        if ($found) {
            $appId = (int)$found['id'];
            $appUpd->execute([$appName ?: null, $exePath ?: null, $end ?: $start, max($dur,0), $appId]);
        } else {
            $appIns->execute([$appName ?: null, $processName, $exePath ?: null, $start, $end ?: $start]);
            $appId = (int)$pdo->lastInsertId();
            // update aggregates for first insert
            $appUpd->execute([$appName ?: null, $exePath ?: null, $end ?: $start, max($dur,0), $appId]);
        }

        // Insert usage row
        $usageIns->execute([
            (int)$user['id'],
            $machineId,
            $appId,
            $appName !== '' ? $appName : $processName,
            $processName,
            $windowTitle,
            $start,
            $end,
            $dur,
            $isProd
        ]);
        $timelineIns->execute([
            (int)$user['id'],
            $machineId,
            $appId,
            $appName !== '' ? $appName : $processName,
            $windowTitle ?: $processName,
            $start,
            $end,
            max($dur,0),
            $isProd
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
	$pdo->rollBack();
	http_response_code(500);
	echo json_encode(['error'=>'Server error','message'=>$e->getMessage()]);
	exit;
}

// Return status + current server settings so agent can adapt
$s = $pdo->prepare('SELECT `key`, `value` FROM settings WHERE `key` IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$s->execute(['agent_sync_interval_seconds', 'parallel_sync_workers', 'delete_screenshots_after_sync', 'device_monitoring_enabled', 'screenshots_enabled', 'screenshot_interval_seconds', 'website_monitoring_enabled', 'website_monitoring_interval_seconds', 'application_monitoring_enabled', 'application_monitoring_interval_seconds']);
$settings = [];
foreach ($s->fetchAll() as $row) {
    $settings[$row['key']] = $row['value'];
}
$syncInterval = (int)($settings['agent_sync_interval_seconds'] ?? 60);
$parallelWorkers = (int)($settings['parallel_sync_workers'] ?? 1);
$deleteScreenshots = (int)($settings['delete_screenshots_after_sync'] ?? 1);
$deviceMonitoring = (int)($settings['device_monitoring_enabled'] ?? 0);
$screenshotsEnabled = (int)($settings['screenshots_enabled'] ?? 1);
$screenshotInterval = (int)($settings['screenshot_interval_seconds'] ?? 300);
$websiteMonitoringEnabled = (int)($settings['website_monitoring_enabled'] ?? 1);
$websiteMonitoringInterval = (int)($settings['website_monitoring_interval_seconds'] ?? 1);
$applicationMonitoringEnabled = (int)($settings['application_monitoring_enabled'] ?? 1);
$applicationMonitoringInterval = (int)($settings['application_monitoring_interval_seconds'] ?? 2);

// Check if device monitoring is enabled for this specific machine
$deviceMonitoringEnabled = 0;
if ($deviceMonitoring) {
    $monStmt = $pdo->prepare('SELECT enabled FROM device_monitoring WHERE machine_id = ?');
    $monStmt->execute([$machineId]);
    $monResult = $monStmt->fetch();
    if ($monResult) {
        $deviceMonitoringEnabled = (int)$monResult['enabled'];
    } else {
        // Create default entry (disabled by default)
        $insMon = $pdo->prepare('INSERT INTO device_monitoring (machine_id, enabled) VALUES (?, 0)');
        $insMon->execute([$machineId]);
    }
}

echo json_encode([
    'status' => 'ok',
    'sync_interval_seconds' => $syncInterval,
    'parallel_sync_workers' => $parallelWorkers,
    'delete_screenshots_after_sync' => (bool)$deleteScreenshots,
    'device_monitoring_enabled' => (bool)$deviceMonitoringEnabled,
    'screenshots_enabled' => (bool)$screenshotsEnabled,
    'screenshot_interval_seconds' => $screenshotInterval,
    'website_monitoring_enabled' => (bool)$websiteMonitoringEnabled,
    'website_monitoring_interval_seconds' => $websiteMonitoringInterval,
    'application_monitoring_enabled' => (bool)$applicationMonitoringEnabled,
    'application_monitoring_interval_seconds' => $applicationMonitoringInterval
]);



