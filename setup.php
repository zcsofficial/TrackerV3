<?php
require_once __DIR__ . '/config.php';
start_session();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$username = trim($_POST['username'] ?? '');
	$password = trim($_POST['password'] ?? '');
	if ($username && $password) {
		$stmt = $pdo->prepare('SELECT id FROM users WHERE role = "superadmin" LIMIT 1');
		$stmt->execute();
		$exists = $stmt->fetch();
		if ($exists) {
			$err = 'Superadmin already exists.';
		} else {
			$hash = password_hash($password, PASSWORD_DEFAULT);
			$ins = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, "superadmin")');
			$ins->execute([$username, $hash]);
			$_SESSION['user'] = [
				'id' => (int)$pdo->lastInsertId(),
				'username' => $username,
				'role' => 'superadmin'
			];
			header('Location: ' . BASE_URL . 'dashboard.php');
			exit;
		}
	} else {
		$err = 'Provide username and password.';
	}
}

?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<title>Setup Superadmin</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5">
	<div class="row justify-content-center">
		<div class="col-md-4">
			<div class="card shadow-sm">
				<div class="card-body">
					<h5 class="card-title mb-3">Initial Setup</h5>
					<p>Create the first superadmin account.</p>
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
						<button class="btn btn-primary w-100" type="submit">Create Superadmin</button>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>
</body>
</html>


