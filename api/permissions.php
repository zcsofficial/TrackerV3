<?php
// API endpoint for device permission checking
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// Handle permission check request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'check') {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    
    if (!$json) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }
    
    $pdo = db();
    $machineIdExt = trim($json['machine_id'] ?? '');
    $deviceHash = trim($json['device_hash'] ?? '');
    
    if (empty($machineIdExt) || empty($deviceHash)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }
    
    // Get machine
    $machineStmt = $pdo->prepare('SELECT id FROM machines WHERE machine_id = ?');
    $machineStmt->execute([$machineIdExt]);
    $machine = $machineStmt->fetch();
    
    if (!$machine) {
        http_response_code(404);
        echo json_encode(['error' => 'Machine not found']);
        exit;
    }
    
    // Check device permission
    $deviceStmt = $pdo->prepare('
        SELECT is_allowed, is_blocked 
        FROM devices 
        WHERE machine_id = ? AND (device_hash = ? OR (vendor_id = ? AND product_id = ? AND serial_number = ?))
        ORDER BY id DESC LIMIT 1
    ');
    $deviceStmt->execute([
        $machine['id'],
        $deviceHash,
        $json['vendor_id'] ?? null,
        $json['product_id'] ?? null,
        $json['serial_number'] ?? null
    ]);
    $device = $deviceStmt->fetch();
    
    $permission = null;
    if ($device) {
        if ($device['is_allowed']) {
            $permission = 'allowed';
        } elseif ($device['is_blocked']) {
            $permission = 'blocked';
        }
    }
    
    echo json_encode([
        'status' => 'ok',
        'permission' => $permission
    ]);
    exit;
}

// Handle permission update (block/allow)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'update') {
    require_login();
    $user = current_user();
    if ($user['role'] !== 'superadmin' && $user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    
    $deviceId = (int)($json['device_id'] ?? 0);
    $action = trim($json['action_type'] ?? '');  // 'allow' or 'block'
    
    if ($deviceId <= 0 || !in_array($action, ['allow', 'block', 'unblock'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }
    
    $pdo = db();
    
    try {
        if ($action === 'allow') {
            $stmt = $pdo->prepare('UPDATE devices SET is_allowed = 1, is_blocked = 0 WHERE id = ?');
        } elseif ($action === 'block') {
            $stmt = $pdo->prepare('UPDATE devices SET is_allowed = 0, is_blocked = 1 WHERE id = ?');
        } else { // unblock
            $stmt = $pdo->prepare('UPDATE devices SET is_blocked = 0 WHERE id = ?');
        }
        
        $stmt->execute([$deviceId]);
        
        echo json_encode([
            'status' => 'ok',
            'message' => "Device {$action}ed successfully"
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Update failed',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);

