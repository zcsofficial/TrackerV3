<?php
// API endpoint for website monitoring and reporting
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
$username = trim($json['user_id'] ?? $json['username'] ?? ''); // Support both user_id and username
$hostname = trim($json['hostname'] ?? '');
$domain = trim($json['domain'] ?? '');
$fullUrl = trim($json['url'] ?? $json['full_url'] ?? ''); // Support both 'url' and 'full_url'
$title = trim($json['title'] ?? '');
$browser = trim($json['browser'] ?? 'Unknown');
$isPrivate = isset($json['is_private']) ? (int)$json['is_private'] : 0;
$isIncognito = isset($json['is_incognito']) ? (int)$json['is_incognito'] : 0;

if (empty($machineIdExt) || empty($domain)) {
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
        // Update visit duration
        $durationSeconds = (int)($json['duration_seconds'] ?? 0);
        $visitEnd = trim($json['visit_end'] ?? date('Y-m-d H:i:s'));
        
        // Find the most recent visit for this domain/user/machine
        $visitStmt = $pdo->prepare('
            SELECT id, duration_seconds FROM website_visits
            WHERE user_id = ? AND machine_id = ? AND domain = ?
            ORDER BY visit_start DESC LIMIT 1
        ');
        $visitStmt->execute([$userId, $machineId, $domain]);
        $visit = $visitStmt->fetch();
        
        if ($visit) {
            $updateStmt = $pdo->prepare('
                UPDATE website_visits
                SET visit_end = ?, duration_seconds = ?
                WHERE id = ?
            ');
            $updateStmt->execute([$visitEnd, $durationSeconds, $visit['id']]);
            
            // Update website stats
            $statsStmt = $pdo->prepare('
                UPDATE websites
                SET total_duration_seconds = total_duration_seconds + ?,
                    last_seen = ?
                WHERE domain = ?
            ');
            $statsStmt->execute([$durationSeconds, $visitEnd, $domain]);
        }
        
        echo json_encode(['status' => 'ok', 'duration_updated' => $durationSeconds]);
        exit;
    }
    
    // Action: report (new visit)
    $visitStart = trim($json['visit_start'] ?? date('Y-m-d H:i:s'));
    
    // Upsert website
    $websiteStmt = $pdo->prepare('SELECT id FROM websites WHERE domain = ? LIMIT 1');
    $websiteStmt->execute([$domain]);
    $website = $websiteStmt->fetch();
    
    if ($website) {
        $websiteId = (int)$website['id'];
        $updateStmt = $pdo->prepare('
            UPDATE websites
            SET last_seen = ?,
                total_visits = total_visits + 1,
                title = COALESCE(?, title)
            WHERE id = ?
        ');
        $updateStmt->execute([$visitStart, $title ?: null, $websiteId]);
    } else {
        $insertStmt = $pdo->prepare('
            INSERT INTO websites (domain, full_url, title, first_seen, last_seen, total_visits)
            VALUES (?, ?, ?, ?, ?, 1)
        ');
        $insertStmt->execute([$domain, $fullUrl, $title, $visitStart, $visitStart]);
        $websiteId = (int)$pdo->lastInsertId();
    }
    
    // Check if website is blocked
    $isBlocked = false;
    
    // Check global blocks
    $globalBlockStmt = $pdo->prepare('
        SELECT id FROM website_blocks
        WHERE is_active = 1
        AND (
            (block_type = "global" AND website_id = ?)
            OR (block_type = "global" AND category_id IN (SELECT category_id FROM websites WHERE id = ?))
            OR (block_type = "global" AND domain_pattern IS NOT NULL AND ? LIKE CONCAT("%", domain_pattern, "%"))
        )
        LIMIT 1
    ');
    $globalBlockStmt->execute([$websiteId, $websiteId, $domain]);
    if ($globalBlockStmt->fetch()) {
        $isBlocked = true;
    }
    
    // Check user-specific blocks
    if (!$isBlocked) {
        $userBlockStmt = $pdo->prepare('
            SELECT id FROM website_blocks
            WHERE is_active = 1
            AND block_type = "user"
            AND user_id = ?
            AND (
                website_id = ?
                OR category_id IN (SELECT category_id FROM websites WHERE id = ?)
                OR (domain_pattern IS NOT NULL AND ? LIKE CONCAT("%", domain_pattern, "%"))
            )
            LIMIT 1
        ');
        $userBlockStmt->execute([$userId, $websiteId, $websiteId, $domain]);
        if ($userBlockStmt->fetch()) {
            $isBlocked = true;
        }
    }
    
    // Check machine-specific blocks
    if (!$isBlocked) {
        $machineBlockStmt = $pdo->prepare('
            SELECT id FROM website_blocks
            WHERE is_active = 1
            AND block_type = "machine"
            AND machine_id = ?
            AND (
                website_id = ?
                OR category_id IN (SELECT category_id FROM websites WHERE id = ?)
                OR (domain_pattern IS NOT NULL AND ? LIKE CONCAT("%", domain_pattern, "%"))
            )
            LIMIT 1
        ');
        $machineBlockStmt->execute([$machineId, $websiteId, $websiteId, $domain]);
        if ($machineBlockStmt->fetch()) {
            $isBlocked = true;
        }
    }
    
    // Insert visit record
    $visitStmt = $pdo->prepare('
        INSERT INTO website_visits
        (user_id, machine_id, website_id, domain, full_url, title, browser, is_private, is_incognito, visit_start, duration_seconds)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
    ');
    $visitStmt->execute([
        $userId, $machineId, $websiteId, $domain, $fullUrl, $title, $browser,
        $isPrivate, $isIncognito, $visitStart
    ]);
    
    echo json_encode([
        'status' => 'ok',
        'is_blocked' => $isBlocked,
        'website_id' => $websiteId
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

