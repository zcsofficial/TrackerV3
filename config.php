<?php
// Basic configuration and database connection helper

// Update these constants for your environment
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'tracker_v3');
define('DB_USER', 'root');
define('DB_PASS', 'Adnan@66202');
define('DB_CHARSET', 'utf8mb4');

// Base URL of the app (used for links); adjust if not running at document root
// Example: define('BASE_URL', '/TrackerV3/');
define('BASE_URL', '/');

// Storage path for screenshots
define('STORAGE_PATH', __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'screenshots');

if (!is_dir(STORAGE_PATH)) {
	@mkdir(STORAGE_PATH, 0775, true);
}

function db(): PDO {
	static $pdo = null;
	if ($pdo === null) {
		$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
		$options = [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
		];
		$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
	}
	return $pdo;
}

function start_session(): void {
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
}

function require_login(): void {
	start_session();
	if (empty($_SESSION['user'])) {
		header('Location: ' . BASE_URL . 'index.php');
		exit;
	}
}

function current_user() {
	start_session();
	return $_SESSION['user'] ?? null;
}

function is_role(string $role): bool {
	$user = current_user();
	return $user && isset($user['role']) && $user['role'] === $role;
}

?>


