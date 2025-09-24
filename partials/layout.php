<?php
require_once __DIR__ . '/../config.php';

function render_layout(string $pageTitle, callable $contentRenderer): void {
	$user = current_user();
	$role = $user['role'] ?? '';
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo htmlspecialchars($pageTitle); ?> - Tracker</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
	<link href="<?php echo BASE_URL; ?>assets/styles.css" rel="stylesheet">
</head>
<body>
	<div class="d-flex">
		<aside class="sidebar d-flex flex-column p-3 text-bg-dark">
			<a href="<?php echo BASE_URL; ?>dashboard.php" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-white text-decoration-none">
				<i class="bi bi-graph-up-arrow fs-4 me-2"></i>
				<span class="fs-5">Tracker</span>
			</a>
			<hr>
			<ul class="nav nav-pills flex-column mb-auto">
				<li class="nav-item"><a href="<?php echo BASE_URL; ?>dashboard.php" class="nav-link text-white"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
				<li><a href="<?php echo BASE_URL; ?>users.php" class="nav-link text-white"><i class="bi bi-people me-2"></i>Users</a></li>
				<li><a href="<?php echo BASE_URL; ?>agents.php" class="nav-link text-white"><i class="bi bi-pc-display me-2"></i>Agents</a></li>
				<?php if ($role === 'superadmin' || $role === 'admin'): ?>
				<li><a href="<?php echo BASE_URL; ?>settings.php" class="nav-link text-white"><i class="bi bi-gear me-2"></i>Settings</a></li>
				<?php endif; ?>
			</ul>
			<hr>
			<div class="dropdown">
				<a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
					<i class="bi bi-person-circle fs-5 me-2"></i>
					<strong><?php echo htmlspecialchars($user['username'] ?? ''); ?></strong>
				</a>
				<ul class="dropdown-menu dropdown-menu-dark text-small shadow">
					<li><span class="dropdown-item disabled">Role: <?php echo htmlspecialchars($role); ?></span></li>
					<li><hr class="dropdown-divider"></li>
					<li><a class="dropdown-item" href="<?php echo BASE_URL; ?>logout.php">Sign out</a></li>
				</ul>
			</div>
		</aside>
		<main class="flex-fill">
			<nav class="navbar navbar-light bg-white border-bottom px-3">
				<div class="ms-auto">
					<a class="btn btn-outline-secondary btn-sm" href="<?php echo BASE_URL; ?>logout.php"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
				</div>
			</nav>
			<div class="container-fluid py-4">
				<?php $contentRenderer(); ?>
			</div>
		</main>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php }


