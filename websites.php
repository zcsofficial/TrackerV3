<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/partials/layout.php';
require_login();
$user = current_user();
if ($user['role'] !== 'superadmin' && $user['role'] !== 'admin') {
	http_response_code(403);
	echo 'Forbidden';
	exit;
}
$pdo = db();
$currentUserId = (int)$user['id'];

// Handle POST: Block/Unblock website
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'block_website') {
	$websiteId = (int)($_POST['website_id'] ?? 0);
	$categoryId = (int)($_POST['category_id'] ?? 0);
	$domainPattern = trim($_POST['domain_pattern'] ?? '');
	$blockType = trim($_POST['block_type'] ?? 'global'); // global, user, machine
	$targetUserId = $blockType === 'user' ? (int)($_POST['user_id'] ?? 0) : null;
	$targetMachineId = $blockType === 'machine' ? (int)($_POST['machine_id'] ?? 0) : null;
	$blockReason = trim($_POST['block_reason'] ?? '');
	$isActive = isset($_POST['is_active']) ? 1 : 0;
	
	if ($websiteId > 0 || $categoryId > 0 || !empty($domainPattern)) {
		try {
			$stmt = $pdo->prepare('
				INSERT INTO website_blocks 
				(website_id, category_id, user_id, machine_id, block_type, domain_pattern, is_active, block_reason, created_by)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
				ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), block_reason = VALUES(block_reason), updated_at = NOW()
			');
			
			// Handle duplicate key check - use composite unique key or check existing
			$checkStmt = $pdo->prepare('
				SELECT id FROM website_blocks 
				WHERE website_id = ? AND category_id = ? AND user_id = ? AND machine_id = ? AND block_type = ?
				LIMIT 1
			');
			$checkStmt->execute([$websiteId ?: null, $categoryId ?: null, $targetUserId, $targetMachineId, $blockType]);
			$existing = $checkStmt->fetch();
			
			if ($existing) {
				$updateStmt = $pdo->prepare('
					UPDATE website_blocks 
					SET is_active = ?, block_reason = ?, updated_at = NOW()
					WHERE id = ?
				');
				$updateStmt->execute([$isActive, $blockReason, $existing['id']]);
			} else {
				$stmt->execute([
					$websiteId ?: null, $categoryId ?: null, $targetUserId, $targetMachineId, $blockType,
					$domainPattern ?: null, $isActive, $blockReason ?: null, $currentUserId
				]);
			}
			
			$_SESSION['success'] = 'Website block rule ' . ($isActive ? 'created' : 'updated') . ' successfully';
		} catch (Exception $e) {
			$_SESSION['error'] = 'Failed to update website block: ' . $e->getMessage();
		}
	}
	header('Location: ' . BASE_URL . 'websites.php');
	exit;
}

// Handle POST: Delete block
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_block') {
	$blockId = (int)($_POST['block_id'] ?? 0);
	
	if ($blockId > 0) {
		try {
			$stmt = $pdo->prepare('DELETE FROM website_blocks WHERE id = ?');
			$stmt->execute([$blockId]);
			$_SESSION['success'] = 'Block rule deleted successfully';
		} catch (Exception $e) {
			$_SESSION['error'] = 'Failed to delete block: ' . $e->getMessage();
		}
	}
	header('Location: ' . BASE_URL . 'websites.php');
	exit;
}

// Handle POST: Bulk block websites
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_block_websites') {
	$websiteIds = $_POST['website_ids'] ?? [];
	$blockType = trim($_POST['bulk_block_type'] ?? 'global');
	$targetUserId = $blockType === 'user' ? (int)($_POST['bulk_user_id'] ?? 0) : null;
	$targetMachineId = $blockType === 'machine' ? (int)($_POST['bulk_machine_id'] ?? 0) : null;
	$blockReason = trim($_POST['bulk_block_reason'] ?? 'Bulk block action');
	
	if (!empty($websiteIds) && is_array($websiteIds)) {
		try {
			$pdo->beginTransaction();
			
			$stmt = $pdo->prepare('
				INSERT INTO website_blocks 
				(website_id, category_id, user_id, machine_id, block_type, is_active, block_reason, created_by)
				VALUES (?, NULL, ?, ?, ?, 1, ?, ?)
				ON DUPLICATE KEY UPDATE is_active = 1, updated_at = NOW()
			');
			
			$count = 0;
			foreach ($websiteIds as $websiteId) {
				$websiteId = (int)$websiteId;
				if ($websiteId > 0) {
					$stmt->execute([$websiteId, $targetUserId, $targetMachineId, $blockType, $blockReason, $currentUserId]);
					$count++;
				}
			}
			
			$pdo->commit();
			$_SESSION['success'] = "Blocked $count website(s) successfully";
		} catch (Exception $e) {
			$pdo->rollBack();
			$_SESSION['error'] = 'Failed to bulk block websites: ' . $e->getMessage();
		}
	}
	header('Location: ' . BASE_URL . 'websites.php');
	exit;
}

// Handle POST: Add/Edit category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_category') {
	$categoryId = (int)($_POST['category_id'] ?? 0);
	$categoryName = trim($_POST['category_name'] ?? '');
	$categoryDesc = trim($_POST['category_description'] ?? '');
	$categoryColor = trim($_POST['category_color'] ?? '#3498db');
	
	if (!empty($categoryName)) {
		try {
			if ($categoryId > 0) {
				$stmt = $pdo->prepare('UPDATE website_categories SET name = ?, description = ?, color = ? WHERE id = ?');
				$stmt->execute([$categoryName, $categoryDesc ?: null, $categoryColor, $categoryId]);
				$_SESSION['success'] = 'Category updated successfully';
			} else {
				$stmt = $pdo->prepare('INSERT INTO website_categories (name, description, color) VALUES (?, ?, ?)');
				$stmt->execute([$categoryName, $categoryDesc ?: null, $categoryColor]);
				$_SESSION['success'] = 'Category created successfully';
			}
		} catch (Exception $e) {
			$_SESSION['error'] = 'Failed to save category: ' . $e->getMessage();
		}
	}
	header('Location: ' . BASE_URL . 'websites.php');
	exit;
}

// Handle POST: Assign category to website
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_category') {
	$websiteId = (int)($_POST['website_id'] ?? 0);
	$categoryId = (int)($_POST['category_id'] ?? 0);
	
	if ($websiteId > 0) {
		try {
			$stmt = $pdo->prepare('UPDATE websites SET category_id = ? WHERE id = ?');
			$stmt->execute([$categoryId ?: null, $websiteId]);
			$_SESSION['success'] = 'Category assigned successfully';
		} catch (Exception $e) {
			$_SESSION['error'] = 'Failed to assign category: ' . $e->getMessage();
		}
	}
	header('Location: ' . BASE_URL . 'websites.php');
	exit;
}

// Filters
$filterCategory = (int)($_GET['category_id'] ?? 0);
$filterBlocked = isset($_GET['blocked']) ? (int)$_GET['blocked'] : null;
$filterDomain = trim($_GET['domain'] ?? '');
$filterUser = (int)($_GET['user_id'] ?? 0);

$whereClauses = [];
$params = [];

if ($filterCategory > 0) {
	$whereClauses[] = 'w.category_id = ?';
	$params[] = $filterCategory;
}

if ($filterDomain) {
	$whereClauses[] = 'w.domain LIKE ?';
	$params[] = '%' . $filterDomain . '%';
}

$whereSQL = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Get websites
$websitesStmt = $pdo->prepare("
	SELECT w.id, w.domain, w.full_url, w.title, w.category_id, w.first_seen, w.last_seen,
	       w.total_visits, w.total_duration_seconds,
	       c.name AS category_name, c.color AS category_color,
	       (SELECT COUNT(*) FROM website_blocks WHERE (website_id = w.id OR category_id = w.category_id) AND is_active = 1 AND block_type = 'global') > 0 AS is_globally_blocked
	FROM websites w
	LEFT JOIN website_categories c ON c.id = w.category_id
	{$whereSQL}
	ORDER BY w.last_seen DESC
	LIMIT 1000
");
$websitesStmt->execute($params);
$websites = $websitesStmt->fetchAll();

// Get categories
$categories = $pdo->query('SELECT id, name, description, color FROM website_categories ORDER BY name')->fetchAll();

// Get blocks
$blocksStmt = $pdo->prepare("
	SELECT wb.id, wb.website_id, wb.category_id, wb.user_id, wb.machine_id, wb.block_type,
	       wb.domain_pattern, wb.is_active, wb.block_reason, wb.created_at,
	       w.domain AS website_domain, c.name AS category_name,
	       u.username AS user_name, m.machine_id AS machine_identifier
	FROM website_blocks wb
	LEFT JOIN websites w ON w.id = wb.website_id
	LEFT JOIN website_categories c ON c.id = wb.category_id
	LEFT JOIN users u ON u.id = wb.user_id
	LEFT JOIN machines m ON m.id = wb.machine_id
	ORDER BY wb.created_at DESC
	LIMIT 500
");
$blocksStmt->execute();
$blocks = $blocksStmt->fetchAll();

// Get users for filters and blocks
$users = $pdo->query('SELECT id, username FROM users ORDER BY username')->fetchAll();

// Get machines for filters and blocks
$machines = $pdo->query('SELECT id, machine_id, hostname, display_name FROM machines ORDER BY machine_id')->fetchAll();

// Get recent visits
$visits = $pdo->query("
	SELECT wv.id, wv.domain, wv.full_url, wv.title, wv.browser, wv.is_private, wv.is_incognito,
	       wv.visit_start, wv.visit_end, wv.duration_seconds,
	       u.username, m.machine_id AS machine_identifier, m.hostname
	FROM website_visits wv
	LEFT JOIN users u ON u.id = wv.user_id
	LEFT JOIN machines m ON m.id = wv.machine_id
	ORDER BY wv.visit_start DESC
	LIMIT 500
")->fetchAll();

start_session();
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

render_layout('Website Monitoring', function() use ($websites, $categories, $blocks, $visits, $users, $machines, $filterCategory, $filterDomain, $filterBlocked, $filterUser, $success, $error) { ?>
    <h5>Website Monitoring</h5>
    
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#websites" type="button">Websites (<?php echo count($websites); ?>)</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#blocks" type="button">Block Rules (<?php echo count($blocks); ?>)</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#categories" type="button">Categories (<?php echo count($categories); ?>)</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#visits" type="button">Visit History</button>
        </li>
    </ul>
    
    <div class="tab-content">
        <!-- Websites Tab -->
        <div class="tab-pane fade show active" id="websites">
            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0">Filters</h6>
                </div>
                <div class="card-body">
                    <form method="get" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category_id">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo (int)$cat['id']; ?>" <?php echo $filterCategory == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Domain Search</label>
                            <input type="text" class="form-control" name="domain" value="<?php echo htmlspecialchars($filterDomain); ?>" placeholder="e.g. youtube.com">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">User</label>
                            <select class="form-select" name="user_id">
                                <option value="">All Users</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?php echo (int)$u['id']; ?>" <?php echo $filterUser == $u['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($u['username']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">Filter</button>
                            <a href="<?php echo BASE_URL; ?>websites.php" class="btn btn-outline-secondary">Clear</a>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Websites List -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Websites (<?php echo count($websites); ?>)</h6>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllWebsites()">
                            <i class="bi bi-check-square"></i> Select All
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllWebsites()">
                            <i class="bi bi-square"></i> Deselect
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Bulk Block Form -->
                    <form method="post" id="bulkBlockForm" class="mb-3" onsubmit="return confirm('Are you sure you want to block selected websites?')">
                        <input type="hidden" name="action" value="bulk_block_websites">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <select name="bulk_block_type" class="form-select" required onchange="toggleBulkBlockTargets(this.value)">
                                    <option value="global">Global Block</option>
                                    <option value="user">User-Specific Block</option>
                                    <option value="machine">Machine-Specific Block</option>
                                </select>
                            </div>
                            <div class="col-md-3" id="bulkUserSelect" style="display:none;">
                                <select name="bulk_user_id" class="form-select">
                                    <option value="">Select User</option>
                                    <?php foreach ($users as $u): ?>
                                    <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3" id="bulkMachineSelect" style="display:none;">
                                <select name="bulk_machine_id" class="form-select">
                                    <option value="">Select Machine</option>
                                    <?php foreach ($machines as $m): ?>
                                    <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars($m['display_name'] ?: $m['machine_id']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="bulk_block_reason" class="form-control" placeholder="Block reason (optional)">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-danger">
                                    <i class="bi bi-shield-lock"></i> Block Selected
                                </button>
                            </div>
                        </div>
                    </form>
                    
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th width="30"><input type="checkbox" id="selectAllCheckbox" onchange="toggleAllWebsites(this)"></th>
                                    <th>Domain</th>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Visits</th>
                                    <th>Duration</th>
                                    <th>Last Seen</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($websites)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">No websites found</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($websites as $ws): ?>
                                <tr>
                                    <td><input type="checkbox" name="website_ids[]" form="bulkBlockForm" value="<?php echo (int)$ws['id']; ?>" class="website-checkbox"></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($ws['domain']); ?></strong>
                                        <?php if ($ws['full_url'] && $ws['full_url'] !== $ws['domain']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars(substr($ws['full_url'], 0, 60)) . (strlen($ws['full_url']) > 60 ? '...' : ''); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($ws['title'] ?: 'N/A'); ?></td>
                                    <td>
                                        <?php if ($ws['category_name']): ?>
                                        <span class="badge" style="background-color: <?php echo htmlspecialchars($ws['category_color']); ?>">
                                            <?php echo htmlspecialchars($ws['category_name']); ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">Uncategorized</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo number_format((int)$ws['total_visits']); ?></td>
                                    <td><?php 
                                        $hours = floor((int)$ws['total_duration_seconds'] / 3600);
                                        $minutes = floor(((int)$ws['total_duration_seconds'] % 3600) / 60);
                                        if ($hours > 0) echo $hours . 'h ';
                                        echo $minutes . 'm';
                                    ?></td>
                                    <td><?php echo htmlspecialchars($ws['last_seen']); ?></td>
                                    <td>
                                        <?php if ($ws['is_globally_blocked']): ?>
                                        <span class="badge bg-danger">Blocked</span>
                                        <?php else: ?>
                                        <span class="badge bg-success">Allowed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary" onclick='blockWebsite(<?php echo json_encode($ws); ?>)' title="Block">
                                                <i class="bi bi-shield-lock"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary" onclick='assignCategory(<?php echo (int)$ws['id']; ?>, <?php echo (int)($ws['category_id'] ?? 0); ?>)' title="Assign Category">
                                                <i class="bi bi-tag"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Block Rules Tab -->
        <div class="tab-pane fade" id="blocks">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">Block Rules</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Target</th>
                                    <th>Scope</th>
                                    <th>Status</th>
                                    <th>Reason</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($blocks)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No block rules configured</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($blocks as $bl): ?>
                                <tr>
                                    <td>
                                        <?php if ($bl['website_domain']): ?>
                                        <strong><?php echo htmlspecialchars($bl['website_domain']); ?></strong>
                                        <?php elseif ($bl['category_name']): ?>
                                        <span class="badge bg-info">Category: <?php echo htmlspecialchars($bl['category_name']); ?></span>
                                        <?php elseif ($bl['domain_pattern']): ?>
                                        <span class="badge bg-warning">Pattern: <?php echo htmlspecialchars($bl['domain_pattern']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($bl['user_name']): ?>
                                        <i class="bi bi-person"></i> <?php echo htmlspecialchars($bl['user_name']); ?>
                                        <?php elseif ($bl['machine_identifier']): ?>
                                        <i class="bi bi-pc-display"></i> <?php echo htmlspecialchars($bl['machine_identifier']); ?>
                                        <?php else: ?>
                                        <span class="text-muted">All</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo htmlspecialchars(ucfirst($bl['block_type'])); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($bl['is_active']): ?>
                                        <span class="badge bg-danger">Active</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($bl['block_reason'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($bl['created_at']); ?></td>
                                    <td>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this block rule?')">
                                            <input type="hidden" name="action" value="delete_block">
                                            <input type="hidden" name="block_id" value="<?php echo (int)$bl['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Categories Tab -->
        <div class="tab-pane fade" id="categories">
            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0">Categories</h6>
                        </div>
                        <div class="card-body">
                            <div class="list-group">
                                <?php foreach ($categories as $cat): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="badge me-2" style="background-color: <?php echo htmlspecialchars($cat['color']); ?>">
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </span>
                                        <?php if ($cat['description']): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars($cat['description']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick='editCategory(<?php echo json_encode($cat); ?>)'>
                                        <i class="bi bi-pencil"></i> Edit
                                    </button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-primary mt-3" onclick="showCategoryModal()">
                                <i class="bi bi-plus-circle"></i> Add Category
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Visit History Tab -->
        <div class="tab-pane fade" id="visits">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">Recent Website Visits</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Domain</th>
                                    <th>Title</th>
                                    <th>Browser</th>
                                    <th>User</th>
                                    <th>Machine</th>
                                    <th>Private</th>
                                    <th>Duration</th>
                                    <th>Visit Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($visits)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">No visits recorded</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($visits as $v): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($v['domain']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($v['title'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($v['browser'] ?: 'Unknown'); ?></td>
                                    <td><?php echo htmlspecialchars($v['username'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($v['machine_identifier'] ?: 'N/A'); ?></td>
                                    <td>
                                        <?php if ($v['is_private'] || $v['is_incognito']): ?>
                                        <span class="badge bg-warning">Yes</span>
                                        <?php else: ?>
                                        <span class="badge bg-success">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $dur = (int)$v['duration_seconds'];
                                        if ($dur > 0) {
                                            $mins = floor($dur / 60);
                                            $secs = $dur % 60;
                                            echo $mins . 'm ' . $secs . 's';
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($v['visit_start']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Block Website Modal -->
    <div class="modal fade" id="blockWebsiteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="action" value="block_website">
                    <input type="hidden" name="website_id" id="blockWebsiteId">
                    <div class="modal-header">
                        <h5 class="modal-title">Block Website</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Block Type</label>
                            <select name="block_type" class="form-select" onchange="toggleBlockTargets(this.value)" required>
                                <option value="global">Global (All Users)</option>
                                <option value="user">User-Specific</option>
                                <option value="machine">Machine-Specific</option>
                            </select>
                        </div>
                        <div class="mb-3" id="blockUserSelect" style="display:none;">
                            <label class="form-label">User</label>
                            <select name="user_id" class="form-select">
                                <option value="">Select User</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3" id="blockMachineSelect" style="display:none;">
                            <label class="form-label">Machine</label>
                            <select name="machine_id" class="form-select">
                                <option value="">Select Machine</option>
                                <?php foreach ($machines as $m): ?>
                                <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars($m['display_name'] ?: $m['machine_id']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Block Reason</label>
                            <textarea name="block_reason" class="form-control" rows="2" placeholder="Optional reason for blocking"></textarea>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="blockActive" value="1" checked>
                            <label class="form-check-label" for="blockActive">Active</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Block Website</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Assign Category Modal -->
    <div class="modal fade" id="assignCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="action" value="assign_category">
                    <input type="hidden" name="website_id" id="assignCategoryWebsiteId">
                    <div class="modal-header">
                        <h5 class="modal-title">Assign Category</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Category</label>
                            <select name="category_id" class="form-select">
                                <option value="">Uncategorized</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Assign Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Category Modal -->
    <div class="modal fade" id="categoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="action" value="save_category">
                    <input type="hidden" name="category_id" id="categoryId">
                    <div class="modal-header">
                        <h5 class="modal-title" id="categoryModalTitle">Add Category</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Category Name</label>
                            <input type="text" name="category_name" id="categoryName" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="category_description" id="categoryDescription" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Color</label>
                            <input type="color" name="category_color" id="categoryColor" class="form-control form-control-color" value="#3498db">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    function toggleAllWebsites(checkbox) {
        document.querySelectorAll('.website-checkbox').forEach(cb => cb.checked = checkbox.checked);
    }
    
    function selectAllWebsites() {
        document.querySelectorAll('.website-checkbox').forEach(cb => cb.checked = true);
        document.getElementById('selectAllCheckbox').checked = true;
    }
    
    function deselectAllWebsites() {
        document.querySelectorAll('.website-checkbox').forEach(cb => cb.checked = false);
        document.getElementById('selectAllCheckbox').checked = false;
    }
    
    function toggleBulkBlockTargets(type) {
        document.getElementById('bulkUserSelect').style.display = type === 'user' ? 'block' : 'none';
        document.getElementById('bulkMachineSelect').style.display = type === 'machine' ? 'block' : 'none';
    }
    
    function toggleBlockTargets(type) {
        document.getElementById('blockUserSelect').style.display = type === 'user' ? 'block' : 'none';
        document.getElementById('blockMachineSelect').style.display = type === 'machine' ? 'block' : 'none';
    }
    
    function blockWebsite(website) {
        document.getElementById('blockWebsiteId').value = website.id;
        new bootstrap.Modal(document.getElementById('blockWebsiteModal')).show();
    }
    
    function assignCategory(websiteId, currentCategoryId) {
        document.getElementById('assignCategoryWebsiteId').value = websiteId;
        document.querySelector('#assignCategoryModal select[name="category_id"]').value = currentCategoryId || '';
        new bootstrap.Modal(document.getElementById('assignCategoryModal')).show();
    }
    
    function showCategoryModal() {
        document.getElementById('categoryId').value = '';
        document.getElementById('categoryName').value = '';
        document.getElementById('categoryDescription').value = '';
        document.getElementById('categoryColor').value = '#3498db';
        document.getElementById('categoryModalTitle').textContent = 'Add Category';
        new bootstrap.Modal(document.getElementById('categoryModal')).show();
    }
    
    function editCategory(category) {
        document.getElementById('categoryId').value = category.id;
        document.getElementById('categoryName').value = category.name;
        document.getElementById('categoryDescription').value = category.description || '';
        document.getElementById('categoryColor').value = category.color || '#3498db';
        document.getElementById('categoryModalTitle').textContent = 'Edit Category';
        new bootstrap.Modal(document.getElementById('categoryModal')).show();
    }
    </script>
<?php });


