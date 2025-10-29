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

// Handle POST: Add new agent
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_agent') {
	$displayName = trim($_POST['display_name'] ?? '');
	$email = trim($_POST['email'] ?? '');
	$upn = trim($_POST['upn'] ?? '');
	$machineId = trim($_POST['machine_id'] ?? '');
	$hostname = trim($_POST['hostname'] ?? '');
	$userId = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
	
	if (empty($machineId)) {
		$_SESSION['error'] = 'Machine ID is required';
	} else {
		try {
			$stmt = $pdo->prepare('INSERT INTO machines (machine_id, user_id, hostname, display_name, email, upn) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE hostname=VALUES(hostname), display_name=VALUES(display_name), email=VALUES(email), upn=VALUES(upn), user_id=VALUES(user_id)');
			$stmt->execute([$machineId, $userId, $hostname ?: null, $displayName ?: null, $email ?: null, $upn ?: null]);
			$_SESSION['success'] = 'Agent added successfully';
		} catch (Exception $e) {
			$_SESSION['error'] = 'Failed to add agent: ' . $e->getMessage();
		}
	}
	header('Location: ' . BASE_URL . 'agents.php');
	exit;
}

// Handle POST: Delete agent
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_agent') {
	$agentId = (int)($_POST['agent_id'] ?? 0);
	if ($agentId > 0) {
		try {
			$stmt = $pdo->prepare('DELETE FROM machines WHERE id = ?');
			$stmt->execute([$agentId]);
			$_SESSION['success'] = 'Agent deleted successfully';
		} catch (Exception $e) {
			$_SESSION['error'] = 'Failed to delete agent: ' . $e->getMessage();
		}
	}
	header('Location: ' . BASE_URL . 'agents.php');
	exit;
}

// Handle POST: Map agent to user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'map_agent') {
	$agentId = (int)($_POST['agent_id'] ?? 0);
	$userId = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
	if ($agentId > 0) {
		try {
			$stmt = $pdo->prepare('UPDATE machines SET user_id = ? WHERE id = ?');
			$stmt->execute([$userId, $agentId]);
			$_SESSION['success'] = 'Agent mapping updated successfully';
		} catch (Exception $e) {
			$_SESSION['error'] = 'Failed to update agent mapping: ' . $e->getMessage();
		}
	}
	header('Location: ' . BASE_URL . 'agents.php');
	exit;
}

// Get list of users for dropdown (with role for better mapping)
$users = $pdo->query('SELECT id, username, role FROM users ORDER BY username')->fetchAll();

$rows = $pdo->query('SELECT m.id, m.machine_id, m.hostname, m.display_name, m.email, m.upn, m.last_seen, u.username FROM machines m LEFT JOIN users u ON u.id = m.user_id ORDER BY (m.last_seen IS NULL), m.last_seen DESC')->fetchAll();

start_session();
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

render_layout('Agents', function() use ($rows, $users, $success, $error) { ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>Agents</h5>
        <div class="btn-group">
            <a href="<?php echo BASE_URL; ?>installer.py" class="btn btn-outline-primary" download>
                <i class="bi bi-download me-1"></i>Download Installer
            </a>
            <a href="<?php echo BASE_URL; ?>uninstaller.py" class="btn btn-outline-danger" download>
                <i class="bi bi-trash me-1"></i>Download Uninstaller
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAgentModal">
                <i class="bi bi-plus-circle me-1"></i>Add Agent
            </button>
        </div>
    </div>
    <div class="alert alert-info mb-3">
        <strong>Agent Installation:</strong> Download the installer and run it on the target machine. It will automatically download and install the agent to ProgramData folder. Alternatively, you can manually add agents using the "Add Agent" button.
    </div>
    
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
    
    <table class="table table-sm table-striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Machine ID</th>
                <th>Display Name</th>
                <th>Email</th>
                <th>UPN</th>
                <th>Hostname</th>
                <th>User</th>
                <th>Last Seen</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?php echo (int)$r['id']; ?></td>
                <td><?php echo htmlspecialchars($r['machine_id']); ?></td>
                <td><?php echo htmlspecialchars($r['display_name'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($r['email'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($r['upn'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($r['hostname'] ?? '-'); ?></td>
                <td>
                    <?php if ($r['username']): ?>
                        <span class="badge bg-info"><?php echo htmlspecialchars($r['username']); ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Unassigned</span>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($r['last_seen'] ?? 'Never'); ?></td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mapAgentModal<?php echo (int)$r['id']; ?>" title="Map to Employee">
                            <i class="bi bi-link-45deg"></i>
                        </button>
                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteAgentModal<?php echo (int)$r['id']; ?>" title="Delete Agent">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Map Agent Modals -->
    <?php foreach ($rows as $r): ?>
    <div class="modal fade" id="mapAgentModal<?php echo (int)$r['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Map Agent to Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="map_agent">
                    <input type="hidden" name="agent_id" value="<?php echo (int)$r['id']; ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Agent</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($r['machine_id'] . ' - ' . ($r['display_name'] ?: $r['hostname'] ?: 'Unknown')); ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Assign to Employee</label>
                            <select class="form-select" name="user_id">
                                <option value="">-- Unassign (Remove Mapping) --</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?php echo (int)$u['id']; ?>" <?php echo ($r['username'] === $u['username']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($u['username']); ?> (<?php echo htmlspecialchars($u['role']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Select an employee to map this agent to, or choose "Unassign" to remove the mapping.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Mapping</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Agent Modal -->
    <div class="modal fade" id="deleteAgentModal<?php echo (int)$r['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Delete Agent</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="delete_agent">
                    <input type="hidden" name="agent_id" value="<?php echo (int)$r['id']; ?>">
                    <div class="modal-body">
                        <p>Are you sure you want to delete this agent?</p>
                        <div class="alert alert-warning">
                            <strong>Agent Information:</strong><br>
                            Machine ID: <strong><?php echo htmlspecialchars($r['machine_id']); ?></strong><br>
                            Display Name: <strong><?php echo htmlspecialchars($r['display_name'] ?: '-'); ?></strong><br>
                            Hostname: <strong><?php echo htmlspecialchars($r['hostname'] ?: '-'); ?></strong>
                        </div>
                        <p class="text-danger"><strong>Warning:</strong> This action cannot be undone. All activity data associated with this agent will remain in the database.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Agent</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Add Agent Modal -->
    <div class="modal fade" id="addAgentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Agent</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="add_agent">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Machine ID <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="machine_id" required>
                            <div class="form-text">Unique identifier for the machine/agent</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Display Name</label>
                            <input type="text" class="form-control" name="display_name">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">UPN (User Principal Name)</label>
                            <input type="text" class="form-control" name="upn">
                            <div class="form-text">e.g., user@domain.com</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Hostname</label>
                            <input type="text" class="form-control" name="hostname">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Map to Employee <i class="bi bi-info-circle" data-bs-toggle="tooltip" title="Assign this agent to an employee to track their activity"></i></label>
                            <select class="form-select" name="user_id">
                                <option value="">-- None (Unassigned) --</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?php echo (int)$u['id']; ?>">
                                    <?php echo htmlspecialchars($u['username']); ?> 
                                    <?php if ($u['role'] !== 'employee'): ?>
                                        <span class="text-muted">(<?php echo htmlspecialchars($u['role']); ?>)</span>
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Select an employee to map this agent to. The agent will track activity for the selected employee.</div>
                        </div>
                    </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Agent</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Initialize Bootstrap tooltips
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl)
});
</script>
<?php });


