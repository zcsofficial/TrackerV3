<?php
require_once __DIR__ . '/config.php';
require_login();
$user = current_user();
if ($user['role'] !== 'superadmin' && $user['role'] !== 'admin') {
	http_response_code(403);
	echo 'Forbidden';
	exit;
}
$pdo = db();
$rows = $pdo->query('SELECT m.id, m.machine_id, m.hostname, m.last_seen, u.username FROM machines m LEFT JOIN users u ON u.id = m.user_id ORDER BY m.last_seen DESC NULLS LAST')->fetchAll();
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<title>Agents</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
	<div class="container-fluid">
		<a class="navbar-brand" href="dashboard.php">Tracker</a>
		<div>
			<a class="btn btn-outline-light btn-sm" href="users.php">Users</a>
			<a class="btn btn-outline-light btn-sm" href="agents.php">Agents</a>
			<a class="btn btn-outline-light btn-sm" href="logout.php">Logout</a>
		</div>
	</div>
</nav>
<div class="container py-4">
	<h5>Agents (Machines)</h5>
	<table class="table table-sm table-striped">
		<thead><tr><th>ID</th><th>Machine ID</th><th>Hostname</th><th>User</th><th>Last Seen</th></tr></thead>
		<tbody>
		<?php foreach ($rows as $r): ?>
			<tr>
				<td><?php echo (int)$r['id']; ?></td>
				<td><?php echo htmlspecialchars($r['machine_id']); ?></td>
				<td><?php echo htmlspecialchars($r['hostname'] ?? ''); ?></td>
				<td><?php echo htmlspecialchars($r['username'] ?? ''); ?></td>
				<td><?php echo htmlspecialchars($r['last_seen'] ?? ''); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
</body>
</html>


