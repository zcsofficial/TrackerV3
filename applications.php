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

// Handle POST: Block/Unblock application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'block_application') {
	$applicationId = (int)($_POST['application_id'] ?? 0);
	$categoryId = (int)($_POST['category_id'] ?? 0);
	$processName = trim($_POST['process_name'] ?? '');
	$blockType = trim($_POST['block_type'] ?? 'global'); // global, user, machine
	$targetUserId = $blockType === 'user' ? (int)($_POST['user_id'] ?? 0) : null;
	$targetMachineId = $blockType === 'machine' ? (int)($_POST['machine_id'] ?? 0) : null;
	$blockReason = trim($_POST['block_reason'] ?? '');
	$isActive = isset($_POST['is_active']) ? 1 : 0;
	
	if ($applicationId > 0 || $categoryId > 0 || !empty($processName)) {
		try {
			// Check if block already exists
			$checkStmt = $pdo->prepare('
				SELECT id FROM application_blocks 
				WHERE application_id = ? AND category_id = ? AND user_id = ? AND machine_id = ? AND block_type = ? AND process_name = ?
				LIMIT 1
			');
			$checkStmt->execute([$applicationId ?: null, $categoryId ?: null, $targetUserId, $targetMachineId, $blockType, $processName ?: null]);
			$existing = $checkStmt->fetch();
			
			if ($existing) {
				$updateStmt = $pdo->prepare('
					UPDATE application_blocks 
					SET is_active = ?, block_reason = ?, updated_at = NOW()
					WHERE id = ?
				');
				$updateStmt->execute([$isActive, $blockReason, $existing['id']]);
			} else {
				$stmt = $pdo->prepare('
					INSERT INTO application_blocks 
					(application_id, category_id, user_id, machine_id, block_type, process_name, is_active, block_reason, created_by)
					VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
				');
				$stmt->execute([
					$applicationId ?: null, $categoryId ?: null, $targetUserId, $targetMachineId, $blockType,
					$processName ?: null, $isActive, $blockReason ?: null, $currentUserId
				]);
			}
			
			$_SESSION['success'] = 'Application block rule ' . ($isActive ? 'created' : 'updated') . ' successfully';
		} catch (Exception $e) {
			$_SESSION['error'] = 'Failed to update application block: ' . $e->getMessage();
		}
	}
	header('Location: ' . BASE_URL . 'applications.php');
	exit;
}

// Handle POST: Delete block
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_block') {
	$blockId = (int)($_POST['block_id'] ?? 0);
	
	if ($blockId > 0) {
		try {
			$stmt = $pdo->prepare('DELETE FROM application_blocks WHERE id = ?');
			$stmt->execute([$blockId]);
			$_SESSION['success'] = 'Block rule deleted successfully';
		} catch (Exception $e) {
			$_SESSION['error'] = 'Failed to delete block: ' . $e->getMessage();
		}
	}
	header('Location: ' . BASE_URL . 'applications.php');
	exit;
}

// Handle POST: Update category or productivity flag
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_application') {
	$applicationId = (int)($_POST['application_id'] ?? 0);
	$categoryId = isset($_POST['category_id']) ? ((int)$_POST['category_id'] ?: null) : null;
	$isProductive = isset($_POST['is_productive']) ? ((int)$_POST['is_productive'] ?: null) : null;
	
	if ($applicationId > 0) {
		try {
			$stmt = $pdo->prepare('
				UPDATE applications 
				SET category_id = COALESCE(?, category_id), 
				    is_productive = COALESCE(?, is_productive)
				WHERE id = ?
			');
			$stmt->execute([$categoryId, $isProductive, $applicationId]);
			$_SESSION['success'] = 'Application updated successfully';
		} catch (Exception $e) {
			$_SESSION['error'] = 'Failed to update application: ' . $e->getMessage();
		}
	}
	header('Location: ' . BASE_URL . 'applications.php');
	exit;
}

// Handle POST: Bulk block applications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_block_applications') {
	$applicationIds = $_POST['application_ids'] ?? [];
	$blockType = trim($_POST['bulk_block_type'] ?? 'global');
	$targetUserId = $blockType === 'user' ? (int)($_POST['bulk_user_id'] ?? 0) : null;
	$targetMachineId = $blockType === 'machine' ? (int)($_POST['bulk_machine_id'] ?? 0) : null;
	$blockReason = trim($_POST['bulk_block_reason'] ?? 'Bulk block action');
	
	if (!empty($applicationIds) && is_array($applicationIds)) {
		try {
			$pdo->beginTransaction();
			
			$stmt = $pdo->prepare('
				INSERT INTO application_blocks 
				(application_id, category_id, user_id, machine_id, block_type, is_active, block_reason, created_by)
				SELECT ?, NULL, ?, ?, ?, 1, ?, ?
				WHERE NOT EXISTS (
					SELECT 1 FROM application_blocks 
					WHERE application_id = ? AND user_id = ? AND machine_id = ? AND block_type = ?
				)
			');
			
			foreach ($applicationIds as $appId) {
				$stmt->execute([
					$appId, $targetUserId, $targetMachineId, $blockType, $blockReason, $currentUserId,
					$appId, $targetUserId, $targetMachineId, $blockType
				]);
			}
			
			$pdo->commit();
			$_SESSION['success'] = 'Bulk block applied to ' . count($applicationIds) . ' application(s)';
		} catch (Exception $e) {
			$pdo->rollBack();
			$_SESSION['error'] = 'Failed to bulk block applications: ' . $e->getMessage();
		}
	}
	header('Location: ' . BASE_URL . 'applications.php');
	exit;
}

// Filters
$filterCategory = (int)($_GET['category_id'] ?? 0);
$filterBlocked = isset($_GET['blocked']) ? (int)$_GET['blocked'] : null;
$filterName = trim($_GET['name'] ?? '');
$filterUser = (int)($_GET['user_id'] ?? 0);
$filterProductive = isset($_GET['productive']) ? (int)$_GET['productive'] : null;

$whereClauses = [];
$params = [];

if ($filterCategory > 0) {
	$whereClauses[] = 'a.category_id = ?';
	$params[] = $filterCategory;
}

if ($filterName) {
	$whereClauses[] = '(a.name LIKE ? OR a.process_name LIKE ?)';
	$params[] = '%' . $filterName . '%';
	$params[] = '%' . $filterName . '%';
}

if ($filterProductive !== null) {
	$whereClauses[] = 'a.is_productive = ?';
	$params[] = $filterProductive;
}

$whereSQL = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Get applications
$appsStmt = $pdo->prepare("
	SELECT a.id, a.name, a.process_name, a.executable_path, a.category_id, 
	       a.is_productive, a.first_seen, a.last_seen,
	       a.total_usage_seconds, a.total_sessions,
	       c.name AS category_name, c.color AS category_color,
	       (SELECT COUNT(*) FROM application_blocks WHERE (application_id = a.id OR category_id = a.category_id) AND is_active = 1 AND block_type = 'global') > 0 AS is_globally_blocked
	FROM applications a
	LEFT JOIN application_categories c ON c.id = a.category_id
	{$whereSQL}
	ORDER BY a.last_seen DESC
	LIMIT 1000
");
$appsStmt->execute($params);
$applications = $appsStmt->fetchAll();

// Get categories
$categories = $pdo->query('SELECT id, name, description, color, is_productive FROM application_categories ORDER BY name')->fetchAll();

// Get blocks
$blocksStmt = $pdo->prepare("
	SELECT ab.id, ab.application_id, ab.category_id, ab.user_id, ab.machine_id, ab.block_type,
	       ab.process_name, ab.is_active, ab.block_reason, ab.created_at,
	       a.name AS application_name, a.process_name AS app_process_name,
	       c.name AS category_name,
	       u.username AS user_name, m.machine_id AS machine_identifier
	FROM application_blocks ab
	LEFT JOIN applications a ON a.id = ab.application_id
	LEFT JOIN application_categories c ON c.id = ab.category_id
	LEFT JOIN users u ON u.id = ab.user_id
	LEFT JOIN machines m ON m.id = ab.machine_id
	ORDER BY ab.created_at DESC
	LIMIT 500
");
$blocksStmt->execute();
$blocks = $blocksStmt->fetchAll();

// Get users for filters and blocks
$users = $pdo->query('SELECT id, username FROM users ORDER BY username')->fetchAll();

// Get machines for filters and blocks
$machines = $pdo->query('SELECT id, machine_id, hostname, display_name FROM machines ORDER BY machine_id')->fetchAll();

// Get recent usage
$usage = $pdo->query("
	SELECT au.id, au.application_name, au.process_name, au.window_title,
	       au.session_start, au.session_end, au.duration_seconds, au.is_productive,
	       u.username, m.machine_id AS machine_identifier, m.hostname
	FROM application_usage au
	LEFT JOIN users u ON u.id = au.user_id
	LEFT JOIN machines m ON m.id = au.machine_id
	ORDER BY au.session_start DESC
	LIMIT 500
")->fetchAll();

start_session();
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

function fmt_duration($seconds) {
	$seconds = (int)$seconds;
	if ($seconds < 60) return $seconds . 's';
	if ($seconds < 3600) return round($seconds / 60, 1) . 'm';
	return round($seconds / 3600, 2) . 'h';
}

render_layout('Application Monitoring', function() use ($applications, $categories, $blocks, $usage, $users, $machines, $filterCategory, $filterName, $filterBlocked, $filterUser, $filterProductive, $success, $error) { ?>
    <h5>Application Monitoring</h5>
    
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
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#applications-tab">Applications</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#usage-tab">Recent Usage</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#blocks-tab">Block Rules</button>
        </li>
    </ul>
    
    <div class="tab-content">
        <!-- Applications Tab -->
        <div class="tab-pane fade show active" id="applications-tab">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>All Applications</span>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#bulkBlockModal">
                        <i class="bi bi-shield-x"></i> Bulk Block
                    </button>
                </div>
                <div class="card-body">
                    <!-- Filters -->
                    <form method="GET" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <select name="category_id" class="form-select form-select-sm">
                                <option value="0">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo $filterCategory == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="productive" class="form-select form-select-sm">
                                <option value="">All Productivity</option>
                                <option value="1" <?php echo $filterProductive === 1 ? 'selected' : ''; ?>>Productive</option>
                                <option value="0" <?php echo $filterProductive === 0 ? 'selected' : ''; ?>>Unproductive</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <input type="text" name="name" class="form-control form-control-sm" placeholder="Search by name or process..." value="<?php echo htmlspecialchars($filterName); ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Filter</button>
                        </div>
                    </form>
                    
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="selectAll"></th>
                                    <th>Application</th>
                                    <th>Process</th>
                                    <th>Category</th>
                                    <th>Productivity</th>
                                    <th>Total Usage</th>
                                    <th>Sessions</th>
                                    <th>Last Seen</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($applications as $app): ?>
                                <tr>
                                    <td><input type="checkbox" class="app-checkbox" name="app_ids[]" value="<?php echo $app['id']; ?>"></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($app['name']); ?></strong>
                                        <?php if ($app['is_globally_blocked']): ?>
                                        <span class="badge bg-danger ms-1">Blocked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code class="small"><?php echo htmlspecialchars($app['process_name']); ?></code></td>
                                    <td>
                                        <?php if ($app['category_name']): ?>
                                        <span class="badge" style="background-color: <?php echo htmlspecialchars($app['category_color']); ?>">
                                            <?php echo htmlspecialchars($app['category_name']); ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="text-muted">Uncategorized</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($app['is_productive'] === 1): ?>
                                        <span class="badge bg-success">Productive</span>
                                        <?php elseif ($app['is_productive'] === 0): ?>
                                        <span class="badge bg-danger">Unproductive</span>
                                        <?php else: ?>
                                        <span class="text-muted">Unknown</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo fmt_duration($app['total_usage_seconds']); ?></td>
                                    <td><?php echo number_format($app['total_sessions']); ?></td>
                                    <td><?php echo $app['last_seen'] ? date('Y-m-d H:i', strtotime($app['last_seen'])) : 'Never'; ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                onclick="editApplication(<?php echo htmlspecialchars(json_encode($app)); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="blockApplication(<?php echo $app['id']; ?>, '<?php echo htmlspecialchars($app['process_name']); ?>')">
                                            <i class="bi bi-shield-x"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Usage Tab -->
        <div class="tab-pane fade" id="usage-tab">
            <div class="card">
                <div class="card-header">Recent Application Usage</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Application</th>
                                    <th>Process</th>
                                    <th>Window Title</th>
                                    <th>User</th>
                                    <th>Machine</th>
                                    <th>Start Time</th>
                                    <th>Duration</th>
                                    <th>Productivity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($usage as $u): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($u['application_name']); ?></strong></td>
                                    <td><code class="small"><?php echo htmlspecialchars($u['process_name']); ?></code></td>
                                    <td><?php echo htmlspecialchars($u['window_title'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars($u['username']); ?></td>
                                    <td><?php echo htmlspecialchars($u['machine_identifier']); ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($u['session_start'])); ?></td>
                                    <td><?php echo fmt_duration($u['duration_seconds']); ?></td>
                                    <td>
                                        <?php if ($u['is_productive'] === 1): ?>
                                        <span class="badge bg-success">Productive</span>
                                        <?php elseif ($u['is_productive'] === 0): ?>
                                        <span class="badge bg-danger">Unproductive</span>
                                        <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Blocks Tab -->
        <div class="tab-pane fade" id="blocks-tab">
            <div class="card">
                <div class="card-header">Application Block Rules</div>
                <div class="card-body">
                    <button type="button" class="btn btn-sm btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#blockModal">
                        <i class="bi bi-plus"></i> Add Block Rule
                    </button>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Application/Category</th>
                                    <th>Block Type</th>
                                    <th>Target</th>
                                    <th>Status</th>
                                    <th>Reason</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($blocks as $block): ?>
                                <tr>
                                    <td>
                                        <?php if ($block['application_name']): ?>
                                        <strong><?php echo htmlspecialchars($block['application_name']); ?></strong>
                                        <?php elseif ($block['category_name']): ?>
                                        <strong>Category: <?php echo htmlspecialchars($block['category_name']); ?></strong>
                                        <?php elseif ($block['process_name']): ?>
                                        <strong>Process: <?php echo htmlspecialchars($block['process_name']); ?></strong>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($block['block_type']); ?></span></td>
                                    <td>
                                        <?php if ($block['user_name']): ?>
                                        User: <?php echo htmlspecialchars($block['user_name']); ?>
                                        <?php elseif ($block['machine_identifier']): ?>
                                        Machine: <?php echo htmlspecialchars($block['machine_identifier']); ?>
                                        <?php else: ?>
                                        <span class="text-muted">Global</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($block['is_active']): ?>
                                        <span class="badge bg-danger">Active</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($block['block_reason'] ?: '-'); ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($block['created_at'])); ?></td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this block rule?');">
                                            <input type="hidden" name="action" value="delete_block">
                                            <input type="hidden" name="block_id" value="<?php echo $block['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Application Modal -->
    <div class="modal fade" id="editApplicationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update_application">
                    <input type="hidden" name="application_id" id="edit_app_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Application</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Category</label>
                            <select name="category_id" class="form-select" id="edit_category_id">
                                <option value="">No category</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Productivity</label>
                            <select name="is_productive" class="form-select" id="edit_is_productive">
                                <option value="">Unknown</option>
                                <option value="1">Productive</option>
                                <option value="0">Unproductive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Block Application Modal -->
    <div class="modal fade" id="blockModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="block_application">
                    <input type="hidden" name="application_id" id="block_app_id">
                    <input type="hidden" name="process_name" id="block_process_name">
                    <div class="modal-header">
                        <h5 class="modal-title">Block Application</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Block Type</label>
                            <select name="block_type" class="form-select" id="block_type_select" onchange="updateBlockTargets()">
                                <option value="global">Global (All users/machines)</option>
                                <option value="user">User-specific</option>
                                <option value="machine">Machine-specific</option>
                            </select>
                        </div>
                        <div class="mb-3" id="block_user_div" style="display:none;">
                            <label class="form-label">User</label>
                            <select name="user_id" class="form-select">
                                <option value="0">Select user...</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3" id="block_machine_div" style="display:none;">
                            <label class="form-label">Machine</label>
                            <select name="machine_id" class="form-select">
                                <option value="0">Select machine...</option>
                                <?php foreach ($machines as $m): ?>
                                <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['machine_id'] . ($m['display_name'] ? ' - ' . $m['display_name'] : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Block Reason</label>
                            <textarea name="block_reason" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" name="is_active" id="block_is_active" value="1" checked>
                            <label class="form-check-label" for="block_is_active">Active (block is enabled)</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Block Application</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Bulk Block Modal -->
    <div class="modal fade" id="bulkBlockModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="bulk_block_applications">
                    <div class="modal-header">
                        <h5 class="modal-title">Bulk Block Applications</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted">Selected applications will be blocked.</p>
                        <div class="mb-3">
                            <label class="form-label">Block Type</label>
                            <select name="bulk_block_type" class="form-select" onchange="updateBulkBlockTargets()">
                                <option value="global">Global (All users/machines)</option>
                                <option value="user">User-specific</option>
                                <option value="machine">Machine-specific</option>
                            </select>
                        </div>
                        <div class="mb-3" id="bulk_block_user_div" style="display:none;">
                            <label class="form-label">User</label>
                            <select name="bulk_user_id" class="form-select">
                                <option value="0">Select user...</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3" id="bulk_block_machine_div" style="display:none;">
                            <label class="form-label">Machine</label>
                            <select name="bulk_machine_id" class="form-select">
                                <option value="0">Select machine...</option>
                                <?php foreach ($machines as $m): ?>
                                <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['machine_id'] . ($m['display_name'] ? ' - ' . $m['display_name'] : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Block Reason</label>
                            <textarea name="bulk_block_reason" class="form-control" rows="2">Bulk block action</textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Block Selected</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    // Select all checkbox
    document.getElementById('selectAll').addEventListener('change', function() {
        document.querySelectorAll('.app-checkbox').forEach(cb => cb.checked = this.checked);
    });
    
    function editApplication(app) {
        document.getElementById('edit_app_id').value = app.id;
        document.getElementById('edit_category_id').value = app.category_id || '';
        document.getElementById('edit_is_productive').value = app.is_productive !== null ? app.is_productive : '';
        new bootstrap.Modal(document.getElementById('editApplicationModal')).show();
    }
    
    function blockApplication(appId, processName) {
        document.getElementById('block_app_id').value = appId;
        document.getElementById('block_process_name').value = processName || '';
        updateBlockTargets();
        new bootstrap.Modal(document.getElementById('blockModal')).show();
    }
    
    function updateBlockTargets() {
        const blockType = document.getElementById('block_type_select').value;
        document.getElementById('block_user_div').style.display = blockType === 'user' ? 'block' : 'none';
        document.getElementById('block_machine_div').style.display = blockType === 'machine' ? 'block' : 'none';
    }
    
    function updateBulkBlockTargets() {
        const blockType = document.querySelector('[name="bulk_block_type"]').value;
        document.getElementById('bulk_block_user_div').style.display = blockType === 'user' ? 'block' : 'none';
        document.getElementById('bulk_block_machine_div').style.display = blockType === 'machine' ? 'block' : 'none';
    }
    
    // Handle bulk block form submission - collect selected IDs
    document.querySelector('#bulkBlockModal form').addEventListener('submit', function(e) {
        const selected = Array.from(document.querySelectorAll('.app-checkbox:checked')).map(cb => cb.value);
        if (selected.length === 0) {
            e.preventDefault();
            alert('Please select at least one application');
            return;
        }
        selected.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'application_ids[]';
            input.value = id;
            this.appendChild(input);
        });
    });
    </script>
<?php });


