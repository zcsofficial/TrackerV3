<?php
// API endpoint for agent registration/onboarding
// Called by installer after successful installation
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$json = json_decode($raw, true);

if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$pdo = db();

$machineId = trim($json['machine_id'] ?? '');
$hostname = trim($json['hostname'] ?? '');
$displayName = trim($json['display_name'] ?? '');
$email = trim($json['email'] ?? '');
$upn = trim($json['upn'] ?? '');
$username = trim($json['username'] ?? '');

if (empty($machineId)) {
    http_response_code(400);
    echo json_encode(['error' => 'machine_id is required']);
    exit;
}

try {
    // Get or create user if username provided
    $userId = null;
    if (!empty($username)) {
        $userStmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $userStmt->execute([$username]);
        $user = $userStmt->fetch();
        
        if ($user) {
            $userId = (int)$user['id'];
        } else {
            // Auto-create user as employee if doesn't exist
            $randPass = bin2hex(random_bytes(12));
            $hash = password_hash($randPass, PASSWORD_DEFAULT);
            $insU = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, "employee")');
            $insU->execute([$username, $hash]);
            $userId = (int)$pdo->lastInsertId();
        }
    }
    
    // Check if machine already exists
    $machineStmt = $pdo->prepare('SELECT id FROM machines WHERE machine_id = ?');
    $machineStmt->execute([$machineId]);
    $existingMachine = $machineStmt->fetch();
    
    if ($existingMachine) {
        // Update existing machine
        $machineId_db = (int)$existingMachine['id'];
        $updateStmt = $pdo->prepare('
            UPDATE machines 
            SET user_id = ?, 
                hostname = COALESCE(?, hostname), 
                display_name = COALESCE(?, display_name),
                email = COALESCE(?, email),
                upn = COALESCE(?, upn),
                last_seen = NOW()
            WHERE id = ?
        ');
        $updateStmt->execute([
            $userId,
            $hostname ?: null,
            $displayName ?: null,
            $email ?: null,
            $upn ?: null,
            $machineId_db
        ]);
        echo json_encode([
            'status' => 'ok',
            'message' => 'Agent updated successfully',
            'machine_id' => $machineId,
            'id' => $machineId_db
        ]);
    } else {
        // Insert new machine
        $insertStmt = $pdo->prepare('
            INSERT INTO machines (machine_id, user_id, hostname, display_name, email, upn, last_seen)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ');
        $insertStmt->execute([
            $machineId,
            $userId,
            $hostname ?: null,
            $displayName ?: null,
            $email ?: null,
            $upn ?: null
        ]);
        $machineId_db = (int)$pdo->lastInsertId();
        
        echo json_encode([
            'status' => 'ok',
            'message' => 'Agent registered successfully',
            'machine_id' => $machineId,
            'id' => $machineId_db
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Registration failed',
        'message' => $e->getMessage()
    ]);
}

