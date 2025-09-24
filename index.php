<?php
require_once __DIR__ . '/config.php';
start_session();

$pdo = db();

// If no superadmin exists, redirect to setup
$check = $pdo->query("SELECT id FROM users WHERE role='superadmin' LIMIT 1")->fetch();
if (!$check) {
	header('Location: ' . BASE_URL . 'setup.php');
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$username = trim($_POST['username'] ?? '');
	$password = trim($_POST['password'] ?? '');
	$stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
	$stmt->execute([$username]);
	$user = $stmt->fetch();
	if ($user && password_verify($password, $user['password_hash'])) {
		$_SESSION['user'] = [
			'id' => (int)$user['id'],
			'username' => $user['username'],
			'role' => $user['role']
		];
		header('Location: ' . BASE_URL . 'dashboard.php');
		exit;
	} else {
		$err = 'Invalid credentials';
	}
}
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<title>Login - Employee Tracking</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5">
	<div class="row justify-content-center">
		<div class="col-md-4">
			<div class="card shadow-sm">
				<div class="card-body">
					<h5 class="card-title mb-3">Login</h5>
					<?php if (!empty($err)): ?>
					<div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div>
					<?php endif; ?>
					<form method="post">
						<div class="mb-3">
							<label class="form-label">Username</label>
							<input type="text" class="form-control" name="username" required>
						</div>
						<div class="mb-3">
							<label class="form-label">Password</label>
							<input type="password" class="form-control" name="password" required>
						</div>
						<button class="btn btn-primary w-100" type="submit">Sign in</button>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>
</body>
</html>


