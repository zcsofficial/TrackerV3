<?php
require_once __DIR__ . '/config.php';
require_login();
$pdo = db();

$userId = (int)($_GET['id'] ?? 0);
$user = $pdo->prepare('SELECT id, username FROM users WHERE id = ?');
$user->execute([$userId]);
$u = $user->fetch();
if (!$u) { http_response_code(404); echo 'User not found'; exit; }

$stmt = $pdo->prepare('SELECT 
	SUM(productive_seconds) AS prod,
	SUM(unproductive_seconds) AS unprod,
	SUM(idle_seconds) AS idle
	FROM activity WHERE user_id = ? AND DATE(start_time) = CURDATE()');
$stmt->execute([$userId]);
$agg = $stmt->fetch() ?: ['prod'=>0,'unprod'=>0,'idle'=>0];

function fmt_hms($seconds) {
	$seconds = (int)$seconds;
	$h = intdiv($seconds, 3600);
	$m = intdiv($seconds % 3600, 60);
	$s = $seconds % 60;
	return sprintf('%02dh %02dm %02ds', $h, $m, $s);
}

$lastSyncRow = $pdo->prepare('SELECT MAX(created_at) AS last_sync FROM activity WHERE user_id = ?');
$lastSyncRow->execute([$userId]);
$lastSync = $lastSyncRow->fetch();

$shots = $pdo->prepare('SELECT taken_at, filename, filesize_kb FROM screenshots WHERE user_id = ? ORDER BY taken_at DESC LIMIT 20');
$shots->execute([$userId]);
$screens = $shots->fetchAll();
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<title>User Detail</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
	<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
	<div class="container-fluid">
		<a class="navbar-brand" href="users.php">Back</a>
		<div>
			<a class="btn btn-outline-light btn-sm" href="logout.php">Logout</a>
		</div>
	</div>
</nav>
<div class="container py-4">
	<h5>User: <?php echo htmlspecialchars($u['username']); ?></h5>
	<div class="mb-3 small text-muted">Last sync: <?php echo htmlspecialchars($lastSync['last_sync'] ?? 'N/A'); ?></div>
	<div class="row g-3">
		<div class="col-md-6">
			<div class="card"><div class="card-body">
				<h6>Today Productivity</h6>
				<div class="row text-center">
					<div class="col">
						<div class="small text-muted">Productive</div>
						<div class="fw-bold"><?php echo fmt_hms($agg['prod'] ?? 0); ?></div>
					</div>
					<div class="col">
						<div class="small text-muted">Unproductive</div>
						<div class="fw-bold"><?php echo fmt_hms($agg['unprod'] ?? 0); ?></div>
					</div>
					<div class="col">
						<div class="small text-muted">Idle</div>
						<div class="fw-bold"><?php echo fmt_hms($agg['idle'] ?? 0); ?></div>
					</div>
				</div>
				<canvas id="activityChart" height="140"></canvas>
			</div></div>
		</div>
		<div class="col-md-6">
			<div class="card"><div class="card-body"><h6>Screenshots (latest)</h6>
				<div class="row g-2">
					<?php foreach ($screens as $s): ?>
					<div class="col-6">
						<div class="border rounded p-1">
							<div class="small text-muted"><?php echo htmlspecialchars($s['taken_at']); ?> (<?php echo (int)$s['filesize_kb']; ?> KB)</div>
							<img src="<?php echo 'storage/screenshots/' . htmlspecialchars($s['filename']); ?>" class="img-fluid" />
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			</div></div>
		</div>
	</div>
</div>
<script>
const ctx = document.getElementById('activityChart');
new Chart(ctx, {
	type: 'bar',
	data: {
		labels: ['Productive','Unproductive','Idle'],
		datasets: [{
			label: 'Seconds',
			data: [<?php echo (int)$agg['prod']; ?>, <?php echo (int)$agg['unprod']; ?>, <?php echo (int)$agg['idle']; ?>],
			backgroundColor: ['#28a745','#dc3545','#6c757d']
		}]
	}
});
</script>
</body>
</html>


