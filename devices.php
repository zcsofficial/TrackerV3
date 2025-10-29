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

// Handle POST: Toggle device monitoring
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_monitoring') {
	$machineId = (int)($_POST['machine_id'] ?? 0);
	$enabled = isset($_POST['enabled']) ? 1 : 0;
	
	if ($machineId > 0) {
		try {
			$stmt = $pdo->prepare('INSERT INTO device_monitoring (machine_id, enabled) VALUES (?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)');
			$stmt->execute([$machineId, $enabled]);
			$_SESSION['success'] = 'Device monitoring ' . ($enabled ? 'enabled' : 'disabled') . ' successfully';
		} catch (Exception $e) {
			$_SESSION['error'] = 'Failed to update monitoring: ' . $e->getMessage();
		}
	}
	header('Location: ' . BASE_URL . 'devices.php');
	exit;
}

// Handle POST: Block/Allow device (single)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_device_permission') {
	$deviceId = (int)($_POST['device_id'] ?? 0);
	$permission = trim($_POST['permission'] ?? ''); // 'allow', 'block', 'unblock'
	
	if ($deviceId > 0 && in_array($permission, ['allow', 'block', 'unblock'])) {
		try {
			if ($permission === 'allow') {
				$stmt = $pdo->prepare('UPDATE devices SET is_allowed = 1, is_blocked = 0 WHERE id = ?');
			} elseif ($permission === 'block') {
				$stmt = $pdo->prepare('UPDATE devices SET is_allowed = 0, is_blocked = 1 WHERE id = ?');
			} else {
				$stmt = $pdo->prepare('UPDATE devices SET is_blocked = 0 WHERE id = ?');
			}
			$stmt->execute([$deviceId]);
			
			// Log the action
			$deviceStmt = $pdo->prepare('SELECT user_id, machine_id FROM devices WHERE id = ?');
			$deviceStmt->execute([$deviceId]);
			$deviceInfo = $deviceStmt->fetch();
			if ($deviceInfo) {
				$logStmt = $pdo->prepare('INSERT INTO device_logs (device_id, user_id, machine_id, action, action_time) VALUES (?, ?, ?, ?, NOW())');
				$logStmt->execute([$deviceId, $deviceInfo['user_id'], $deviceInfo['machine_id'], $permission]);
			}
			
			$_SESSION['success'] = 'Device permission updated successfully';
		} catch (Exception $e) {
			$_SESSION['error'] = 'Failed to update device permission: ' . $e->getMessage();
		}
	}
	header('Location: ' . BASE_URL . 'devices.php');
	exit;
}

// Handle POST: Bulk update devices
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_update_devices') {
	$deviceIds = $_POST['device_ids'] ?? [];
	$permission = trim($_POST['bulk_permission'] ?? ''); // 'allow', 'block', 'unblock', 'delete'
	
	if (!empty($deviceIds) && is_array($deviceIds) && in_array($permission, ['allow', 'block', 'unblock', 'delete'])) {
		try {
			$pdo->beginTransaction();
			
			$deviceIds = array_map('intval', $deviceIds);
			$placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
			
			if ($permission === 'delete') {
				// Delete devices and logs (use prepared statement for DELETE)
				$logStmt = $pdo->prepare("DELETE FROM device_logs WHERE device_id IN ($placeholders)");
				$logStmt->execute($deviceIds);
				
				$stmt = $pdo->prepare("DELETE FROM devices WHERE id IN ($placeholders)");
				$stmt->execute($deviceIds);
				$affected = $stmt->rowCount();
				$_SESSION['success'] = "Deleted $affected device(s) successfully";
			} else {
				// Update permissions
				if ($permission === 'allow') {
					$stmt = $pdo->prepare("UPDATE devices SET is_allowed = 1, is_blocked = 0 WHERE id IN ($placeholders)");
				} elseif ($permission === 'block') {
					$stmt = $pdo->prepare("UPDATE devices SET is_allowed = 0, is_blocked = 1 WHERE id IN ($placeholders)");
				} else { // unblock
					$stmt = $pdo->prepare("UPDATE devices SET is_blocked = 0 WHERE id IN ($placeholders)");
				}
				$stmt->execute($deviceIds);
				$affected = $stmt->rowCount();
				
				// Log bulk actions
				if ($permission !== 'delete') {
					$logStmt = $pdo->prepare('INSERT INTO device_logs (device_id, user_id, machine_id, action, action_time) 
					                          SELECT id, user_id, machine_id, ?, NOW() FROM devices WHERE id IN (' . $placeholders . ')');
					$logParams = array_merge([$permission], $deviceIds);
					$logStmt->execute($logParams);
				}
				
				$actionName = $permission === 'allow' ? 'allowed' : ($permission === 'block' ? 'blocked' : 'unblocked');
				$_SESSION['success'] = "Updated $affected device(s) - $actionName";
			}
			
			$pdo->commit();
		} catch (Exception $e) {
			$pdo->rollBack();
			$_SESSION['error'] = 'Failed to bulk update devices: ' . $e->getMessage();
		}
	}
	header('Location: ' . BASE_URL . 'devices.php');
	exit;
}

// Get machines with monitoring status
$machines = $pdo->query('
	SELECT m.id, m.machine_id, m.hostname, m.display_name, u.username,
	       COALESCE(dm.enabled, 0) AS monitoring_enabled
	FROM machines m
	LEFT JOIN users u ON u.id = m.user_id
	LEFT JOIN device_monitoring dm ON dm.machine_id = m.id
	ORDER BY m.last_seen DESC
')->fetchAll();

// Get devices with filters
$filterMachine = (int)($_GET['machine_id'] ?? 0);
$filterUser = (int)($_GET['user_id'] ?? 0);
$filterBlocked = isset($_GET['blocked']) ? (int)$_GET['blocked'] : null;

$whereClauses = [];
$params = [];
if ($filterMachine > 0) {
	$whereClauses[] = 'd.machine_id = ?';
	$params[] = $filterMachine;
}
if ($filterUser > 0) {
	$whereClauses[] = 'd.user_id = ?';
	$params[] = $filterUser;
}
if ($filterBlocked !== null) {
	$whereClauses[] = 'd.is_blocked = ?';
	$params[] = $filterBlocked;
}

$whereSQL = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$devices = $pdo->prepare("
	SELECT d.id, d.device_type, d.device_name, d.vendor_id, d.product_id, d.serial_number,
	       d.first_seen, d.last_seen, d.is_blocked, d.is_allowed,
	       u.username, m.machine_id AS machine_identifier, m.hostname
	FROM devices d
	LEFT JOIN users u ON u.id = d.user_id
	LEFT JOIN machines m ON m.id = d.machine_id
	{$whereSQL}
	ORDER BY d.last_seen DESC
	LIMIT 500
");
$devices->execute($params);
$deviceList = $devices->fetchAll();

// Get users for filter
$users = $pdo->query('SELECT id, username FROM users ORDER BY username')->fetchAll();

start_session();
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

render_layout('Device Monitoring', function() use ($machines, $deviceList, $users, $filterMachine, $filterUser, $filterBlocked, $success, $error) { ?>
    <h5>Device Monitoring</h5>
    
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
    
    <!-- Monitoring Configuration -->
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0">Agent Monitoring Status</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Machine</th>
                            <th>User</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($machines as $m): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($m['display_name'] ?: $m['machine_id']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($m['hostname'] ?: ''); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($m['username'] ?: 'Unassigned'); ?></td>
                            <td>
                                <?php if ($m['monitoring_enabled']): ?>
                                    <span class="badge bg-success">Monitoring Enabled</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Monitoring Disabled</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="toggle_monitoring">
                                    <input type="hidden" name="machine_id" value="<?php echo (int)$m['id']; ?>">
                                    <input type="hidden" name="enabled" value="<?php echo $m['monitoring_enabled'] ? '0' : '1'; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $m['monitoring_enabled'] ? 'btn-warning' : 'btn-success'; ?>">
                                        <?php echo $m['monitoring_enabled'] ? 'Disable' : 'Enable'; ?>
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
    
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0">Filters</h6>
        </div>
        <div class="card-body">
            <form method="get" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Machine</label>
                    <select class="form-select" name="machine_id">
                        <option value="">All Machines</option>
                        <?php foreach ($machines as $m): ?>
                        <option value="<?php echo (int)$m['id']; ?>" <?php echo $filterMachine === (int)$m['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($m['display_name'] ?: $m['machine_id']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">User</label>
                    <select class="form-select" name="user_id">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?php echo (int)$u['id']; ?>" <?php echo $filterUser === (int)$u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['username']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="blocked">
                        <option value="">All</option>
                        <option value="1" <?php echo $filterBlocked === 1 ? 'selected' : ''; ?>>Blocked</option>
                        <option value="0" <?php echo $filterBlocked === 0 ? 'selected' : ''; ?>>Not Blocked</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">Filter</button>
                    <a href="<?php echo BASE_URL; ?>devices.php" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Devices List -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Detected Devices (<?php echo count($deviceList); ?>)</h6>
            <div>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll()">
                    <i class="bi bi-check-square"></i> Select All
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAll()">
                    <i class="bi bi-square"></i> Deselect
                </button>
            </div>
        </div>
        <div class="card-body">
            <!-- Bulk Actions -->
            <form method="post" id="bulkForm" class="mb-3" onsubmit="return confirm('Are you sure you want to perform this bulk action?')">
                <input type="hidden" name="action" value="bulk_update_devices">
                <div class="input-group">
                    <select name="bulk_permission" class="form-select" required>
                        <option value="">-- Bulk Action --</option>
                        <option value="allow">Allow Selected</option>
                        <option value="block">Block Selected</option>
                        <option value="unblock">Unblock Selected</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2-all"></i> Apply to Selected
                    </button>
                </div>
            </form>
            
            <div class="table-responsive">
                <table class="table table-sm table-striped">
                    <thead>
                        <tr>
                            <th width="40">
                                <input type="checkbox" id="checkAll" onchange="toggleAll(this)">
                            </th>
                            <th>Device Name</th>
                            <th>Type</th>
                            <th>Vendor/Product</th>
                            <th>User</th>
                            <th>Machine</th>
                            <th>First Seen</th>
                            <th>Last Seen</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deviceList as $d): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="device_ids[]" value="<?php echo (int)$d['id']; ?>" class="device-checkbox" form="bulkForm">
                            </td>
                            <td><strong><?php echo htmlspecialchars($d['device_name']); ?></strong></td>
                            <td>
                                <span class="badge bg-info"><?php echo htmlspecialchars($d['device_type']); ?></span>
                            </td>
                            <td>
                                <?php if ($d['vendor_id'] || $d['product_id']): ?>
                                    <small><?php echo htmlspecialchars($d['vendor_id'] ?: '-'); ?> / <?php echo htmlspecialchars($d['product_id'] ?: '-'); ?></small>
                                <?php else: ?>
                                    <small class="text-muted">-</small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($d['username'] ?: '-'); ?></td>
                            <td>
                                <small><?php echo htmlspecialchars($d['machine_identifier'] ?: $d['hostname'] ?: '-'); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($d['first_seen']); ?></td>
                            <td><?php echo htmlspecialchars($d['last_seen']); ?></td>
                            <td>
                                <?php if ($d['is_blocked']): ?>
                                    <span class="badge bg-danger">Blocked</span>
                                <?php elseif ($d['is_allowed']): ?>
                                    <span class="badge bg-success">Allowed</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <?php if (!$d['is_allowed']): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="update_device_permission">
                                        <input type="hidden" name="device_id" value="<?php echo (int)$d['id']; ?>">
                                        <input type="hidden" name="permission" value="allow">
                                        <button type="submit" class="btn btn-outline-success" title="Allow Device">
                                            <i class="bi bi-check-circle"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if (!$d['is_blocked']): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="update_device_permission">
                                        <input type="hidden" name="device_id" value="<?php echo (int)$d['id']; ?>">
                                        <input type="hidden" name="permission" value="block">
                                        <button type="submit" class="btn btn-outline-danger" title="Block Device">
                                            <i class="bi bi-x-circle"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="update_device_permission">
                                        <input type="hidden" name="device_id" value="<?php echo (int)$d['id']; ?>">
                                        <input type="hidden" name="permission" value="unblock">
                                        <button type="submit" class="btn btn-outline-warning" title="Unblock Device">
                                            <i class="bi bi-unlock"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($deviceList)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No devices found</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        function toggleAll(checkbox) {
            const checkboxes = document.querySelectorAll('.device-checkbox');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
        }
        
        function selectAll() {
            document.getElementById('checkAll').checked = true;
            toggleAll(document.getElementById('checkAll'));
        }
        
        function deselectAll() {
            document.getElementById('checkAll').checked = false;
            toggleAll(document.getElementById('checkAll'));
        }
        
        // Update bulk form with selected device IDs
        document.getElementById('bulkForm').addEventListener('submit', function(e) {
            const checkboxes = document.querySelectorAll('.device-checkbox:checked');
            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one device');
                return false;
            }
            // Device IDs are already in form as checkboxes
        });
    </script>
<?php });

