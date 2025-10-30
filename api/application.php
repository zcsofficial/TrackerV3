<?php
// API endpoint for application monitoring and reporting
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

$action = trim($json['action'] ?? 'report'); // 'report' or 'update_duration'
$machineIdExt = trim($json['machine_id'] ?? '');
$username = trim($json['user_id'] ?? $json['username'] ?? '');
$applicationName = trim($json['application_name'] ?? '');
$processName = trim($json['process_name'] ?? '');
$windowTitle = trim($json['window_title'] ?? '');
$executablePath = trim($json['executable_path'] ?? '');
$sessionStart = trim($json['session_start'] ?? date('Y-m-d H:i:s'));
$isProductive = isset($json['is_productive']) ? (int)$json['is_productive'] : 1;

if (empty($machineIdExt) || empty($applicationName) || empty($processName)) {
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
    
    // Get user
    $userStmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $userStmt->execute([$username]);
    $user = $userStmt->fetch();
    
    if (!$user) {
        // Auto-create user if not exists
        $randPass = bin2hex(random_bytes(12));
        $hash = password_hash($randPass, PASSWORD_DEFAULT);
        $insU = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, "employee")');
        $insU->execute([$username, $hash]);
        $userId = (int)$pdo->lastInsertId();
    } else {
        $userId = (int)$user['id'];
    }
    
    if ($action === 'update_duration') {
        // Update usage duration
        $durationSeconds = (int)($json['duration_seconds'] ?? 0);
        $sessionEnd = trim($json['session_end'] ?? date('Y-m-d H:i:s'));
        
        // Find the most recent usage for this app/user/machine
        $usageStmt = $pdo->prepare('
            SELECT id, duration_seconds FROM application_usage
            WHERE user_id = ? AND machine_id = ? AND process_name = ? AND application_name = ?
            ORDER BY session_start DESC LIMIT 1
        ');
        $usageStmt->execute([$userId, $machineId, $processName, $applicationName]);
        $usage = $usageStmt->fetch();
        
        if ($usage) {
            $updateStmt = $pdo->prepare('
                UPDATE application_usage
                SET session_end = ?, duration_seconds = ?
                WHERE id = ?
            ');
            $updateStmt->execute([$sessionEnd, $durationSeconds, $usage['id']]);
            
            // Update application stats
            $statsStmt = $pdo->prepare('
                UPDATE applications
                SET total_usage_seconds = total_usage_seconds + ?,
                    last_seen = ?
                WHERE process_name = ?
            ');
            $statsStmt->execute([$durationSeconds, $sessionEnd, $processName]);
        }
        
        echo json_encode(['status' => 'ok', 'duration_updated' => $durationSeconds]);
        exit;
    }
    
    // Action: report (new usage session)
    
    // Upsert application
    $appStmt = $pdo->prepare('SELECT id, category_id, is_productive FROM applications WHERE process_name = ? LIMIT 1');
    $appStmt->execute([$processName]);
    $application = $appStmt->fetch();
    
    if ($application) {
        $applicationId = (int)$application['id'];
        $updateStmt = $pdo->prepare('
            UPDATE applications
            SET last_seen = ?,
                total_sessions = total_sessions + 1,
                name = COALESCE(?, name),
                executable_path = COALESCE(?, executable_path),
                is_productive = COALESCE(?, is_productive)
            WHERE id = ?
        ');
        $updateStmt->execute([
            $sessionStart, 
            $applicationName ?: null, 
            $executablePath ?: null,
            $isProductive ?: $application['is_productive'],
            $applicationId
        ]);
    } else {
        $insertStmt = $pdo->prepare('
            INSERT INTO applications (name, process_name, executable_path, is_productive, first_seen, last_seen, total_sessions)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ');
        $insertStmt->execute([
            $applicationName, 
            $processName, 
            $executablePath ?: null,
            $isProductive,
            $sessionStart, 
            $sessionStart
        ]);
        $applicationId = (int)$pdo->lastInsertId();
    }
    
    // Check if application is blocked
    $isBlocked = false;
    
    // Check global blocks
    $globalBlockStmt = $pdo->prepare('
        SELECT id FROM application_blocks
        WHERE is_active = 1
        AND (
            (block_type = "global" AND application_id = ?)
            OR (block_type = "global" AND category_id IN (SELECT category_id FROM applications WHERE id = ?))
            OR (block_type = "global" AND process_name = ?)
        )
        LIMIT 1
    ');
    $globalBlockStmt->execute([$applicationId, $applicationId, $processName]);
    if ($globalBlockStmt->fetch()) {
        $isBlocked = true;
    }
    
    // Check user-specific blocks
    if (!$isBlocked) {
        $userBlockStmt = $pdo->prepare('
            SELECT id FROM application_blocks
            WHERE is_active = 1
            AND block_type = "user"
            AND user_id = ?
            AND (
                application_id = ?
                OR category_id IN (SELECT category_id FROM applications WHERE id = ?)
                OR process_name = ?
            )
            LIMIT 1
        ');
        $userBlockStmt->execute([$userId, $applicationId, $applicationId, $processName]);
        if ($userBlockStmt->fetch()) {
            $isBlocked = true;
        }
    }
    
    // Check machine-specific blocks
    if (!$isBlocked) {
        $machineBlockStmt = $pdo->prepare('
            SELECT id FROM application_blocks
            WHERE is_active = 1
            AND block_type = "machine"
            AND machine_id = ?
            AND (
                application_id = ?
                OR category_id IN (SELECT category_id FROM applications WHERE id = ?)
                OR process_name = ?
            )
            LIMIT 1
        ');
        $machineBlockStmt->execute([$machineId, $applicationId, $applicationId, $processName]);
        if ($machineBlockStmt->fetch()) {
            $isBlocked = true;
        }
    }
    
    // Insert usage record
    $usageStmt = $pdo->prepare('
        INSERT INTO application_usage
        (user_id, machine_id, application_id, application_name, process_name, window_title, session_start, duration_seconds, is_productive)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)
    ');
    $usageStmt->execute([
        $userId, $machineId, $applicationId, $applicationName, $processName, 
        $windowTitle ?: null, $sessionStart, $isProductive ?: null
    ]);
    
    // Insert into unified activity timeline
    $timelineStmt = $pdo->prepare('
        INSERT INTO activity_timeline
        (user_id, machine_id, activity_type, application_id, item_name, item_detail, start_time, duration_seconds, is_productive)
        VALUES (?, ?, "application", ?, ?, ?, ?, 0, ?)
    ');
    $timelineStmt->execute([
        $userId, $machineId, $applicationId, $applicationName, 
        $windowTitle ?: $processName, $sessionStart, $isProductive ?: null
    ]);
    
    echo json_encode([
        'status' => 'ok',
        'is_blocked' => $isBlocked,
        'application_id' => $applicationId
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
    exit;
}


