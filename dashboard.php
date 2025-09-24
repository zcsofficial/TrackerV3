<?php
require_once __DIR__ . '/config.php';
require_login();
$pdo = db();

$totalUsers = (int)$pdo->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
$totalMachines = (int)$pdo->query('SELECT COUNT(*) AS c FROM machines')->fetch()['c'];
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT 
	SUM(productive_seconds) AS prod,
	SUM(unproductive_seconds) AS unprod,
	SUM(idle_seconds) AS idle
	FROM activity WHERE DATE(start_time) = ?");
$stmt->execute([$today]);
$agg = $stmt->fetch() ?: ['prod'=>0,'unprod'=>0,'idle'=>0];
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<title>Dashboard</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
	<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
	<div class="container-fluid">
		<a class="navbar-brand" href="#">Tracker</a>
		<div>
			<a class="btn btn-outline-light btn-sm" href="users.php">Users</a>
			<a class="btn btn-outline-light btn-sm" href="agents.php">Agents</a>
			<a class="btn btn-outline-light btn-sm" href="logout.php">Logout</a>
		</div>
	</div>
</nav>
<div class="container py-4">
	<div class="row g-3">
		<div class="col-md-4">
			<div class="card"><div class="card-body"><h6>Total Users</h6><div class="fs-3"><?php echo $totalUsers; ?></div></div></div>
		</div>
		<div class="col-md-4">
			<div class="card"><div class="card-body"><h6>Total Machines</h6><div class="fs-3"><?php echo $totalMachines; ?></div></div></div>
		</div>
		<div class="col-md-4">
			<div class="card"><div class="card-body"><h6>Today Activity (sec)</h6><canvas id="activityChart" height="120"></canvas></div></div>
		</div>
	</div>
</div>
<script>
const ctx = document.getElementById('activityChart');
new Chart(ctx, {
	type: 'doughnut',
	data: {
		labels: ['Productive','Unproductive','Idle'],
		datasets: [{
			data: [<?php echo (int)$agg['prod']; ?>, <?php echo (int)$agg['unprod']; ?>, <?php echo (int)$agg['idle']; ?>],
			backgroundColor: ['#28a745','#dc3545','#6c757d']
		}]
	}
});
</script>
</body>
</html>


