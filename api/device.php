<?php
// API endpoint for device monitoring and reporting
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

$machineIdExt = trim($json['machine_id'] ?? '');
$username = trim($json['user_id'] ?? '');  // Note: agent sends username as user_id
$hostname = trim($json['hostname'] ?? '');
$deviceType = trim($json['device_type'] ?? 'USB');
$vendorId = trim($json['vendor_id'] ?? '');
$productId = trim($json['product_id'] ?? '');
$serialNumber = trim($json['serial_number'] ?? '');
$deviceName = trim($json['device_name'] ?? 'Unknown Device');
$devicePath = trim($json['device_path'] ?? '');
$deviceHash = trim($json['device_hash'] ?? '');
$action = trim($json['action'] ?? 'connected');  // connected, disconnected, blocked
$isBlocked = isset($json['is_blocked']) ? (bool)$json['is_blocked'] : false;
$timestamp = trim($json['timestamp'] ?? date('Y-m-d H:i:s'));

if (empty($machineIdExt) || empty($deviceName)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

try {
    // Get machine
    $machineStmt = $pdo->prepare('SELECT id FROM machines WHERE machine_id = ?');
    $machineStmt->execute([$machineIdExt]);
    $machine = $machineStmt->fetch();
    
    if (!$machine) {
        http_response_code(404);
        echo json_encode(['error' => 'Machine not found']);
        exit;
    }
    
    $machineId = (int)$machine['id'];
    
    // Generate device hash if not provided
    if (empty($deviceHash)) {
        $deviceHash = md5($vendorId . $productId . $serialNumber . $deviceName);
    }
    
    // Get user if username provided
    $userId = null;
    if (!empty($username)) {
        $userStmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $userStmt->execute([$username]);
        $user = $userStmt->fetch();
        if ($user) {
            $userId = (int)$user['id'];
        }
    }
    
    // Get or create device
    $deviceStmt = $pdo->prepare('
        SELECT id FROM devices 
        WHERE machine_id = ? 
        AND ((vendor_id = ? AND product_id = ? AND serial_number = ?) OR device_hash = ?)
        LIMIT 1
    ');
    $deviceStmt->execute([
        $machineId,
        $vendorId ?: null,
        $productId ?: null,
        $serialNumber ?: null,
        $deviceHash ?: ''
    ]);
    $device = $deviceStmt->fetch();
    
    $pdo->beginTransaction();
    
    if ($device) {
        $deviceId = (int)$device['id'];
        // Update device (also update blocked status if provided)
        $updateStmt = $pdo->prepare('
            UPDATE devices 
            SET last_seen = ?, is_blocked = ?, user_id = ?
            WHERE id = ?
        ');
        $updateStmt->execute([$timestamp, $isBlocked ? 1 : 0, $userId, $deviceId]);
    } else {
        // Check if device should be blocked by default when monitoring enabled
        // Get monitoring status for this machine
        $monStmt = $pdo->prepare('SELECT enabled FROM device_monitoring WHERE machine_id = ?');
        $monStmt->execute([$machineId]);
        $monResult = $monStmt->fetch();
        $monitoringEnabled = $monResult ? (bool)$monResult['enabled'] : false;
        
        // If monitoring enabled, block by default unless explicitly allowed
        $shouldBlock = $monitoringEnabled && !$isBlocked ? true : $isBlocked;
        
        // Insert new device
        $insertStmt = $pdo->prepare('
            INSERT INTO devices 
            (user_id, machine_id, device_type, vendor_id, product_id, serial_number, 
             device_name, device_path, first_seen, last_seen, is_blocked, is_allowed)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
        ');
        $insertStmt->execute([
            $userId,
            $machineId,
            $deviceType,
            $vendorId ?: null,
            $productId ?: null,
            $serialNumber ?: null,
            $deviceName,
            $devicePath ?: null,
            $timestamp,
            $timestamp,
            $shouldBlock ? 1 : 0
        ]);
        $deviceId = (int)$pdo->lastInsertId();
    }
    
    // Log device event
    $logStmt = $pdo->prepare('
        INSERT INTO device_logs 
        (device_id, user_id, machine_id, action, action_time, details)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $logDetails = json_encode([
        'device_hash' => $deviceHash,
        'device_path' => $devicePath
    ]);
    $logStmt->execute([
        $deviceId,
        $userId,
        $machineId,
        $action,
        $timestamp,
        $logDetails
    ]);
    
    $pdo->commit();
    
    // Return device permission status
    $permissionStmt = $pdo->prepare('SELECT is_allowed, is_blocked FROM devices WHERE id = ?');
    $permissionStmt->execute([$deviceId]);
    $permission = $permissionStmt->fetch();
    
    $permissionStatus = null;
    if ($permission['is_allowed']) {
        $permissionStatus = 'allowed';
    } elseif ($permission['is_blocked']) {
        $permissionStatus = 'blocked';
    }
    
    echo json_encode([
        'status' => 'ok',
        'device_id' => $deviceId,
        'permission' => $permissionStatus
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
}

