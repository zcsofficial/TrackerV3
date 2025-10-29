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

// Handle POST: Delete user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
	$userId = (int)($_POST['user_id'] ?? 0);
	$currentUserId = (int)$user['id'];
	
	if ($userId === $currentUserId) {
		$_SESSION['error'] = 'You cannot delete your own account';
	} elseif ($userId > 0) {
		try {
			// Check if user has superadmin role (prevent deleting last superadmin)
			$checkStmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
			$checkStmt->execute([$userId]);
			$targetUser = $checkStmt->fetch();
			
			if ($targetUser && $targetUser['role'] === 'superadmin') {
				// Check if this is the last superadmin
				$superadminCount = $pdo->query('SELECT COUNT(*) FROM users WHERE role = "superadmin"')->fetchColumn();
				if ($superadminCount <= 1) {
					$_SESSION['error'] = 'Cannot delete the last superadmin account';
				} else {
					$stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
					$stmt->execute([$userId]);
					$_SESSION['success'] = 'User deleted successfully';
				}
			} else {
				$stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
				$stmt->execute([$userId]);
				$_SESSION['success'] = 'User deleted successfully';
			}
		} catch (Exception $e) {
			$_SESSION['error'] = 'Failed to delete user: ' . $e->getMessage();
		}
	}
	header('Location: ' . BASE_URL . 'users.php');
	exit;
}

$users = $pdo->query('SELECT u.id, u.username, u.role, u.created_at, COUNT(m.id) AS agent_count FROM users u LEFT JOIN machines m ON m.user_id = u.id GROUP BY u.id ORDER BY u.created_at DESC')->fetchAll();

start_session();
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

render_layout('Users', function() use ($users, $user, $success, $error) { ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>Users / Employees</h5>
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
                <th>Username</th>
                <th>Role</th>
                <th>Agents</th>
                <th>Joined</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?php echo (int)$u['id']; ?></td>
                <td><?php echo htmlspecialchars($u['username']); ?></td>
                <td>
                    <?php
                    $roleColors = [
                        'superadmin' => 'danger',
                        'admin' => 'warning',
                        'hr' => 'info',
                        'employee' => 'secondary'
                    ];
                    $color = $roleColors[$u['role']] ?? 'secondary';
                    ?>
                    <span class="badge bg-<?php echo $color; ?>"><?php echo htmlspecialchars($u['role']); ?></span>
                </td>
                <td>
                    <span class="badge bg-primary"><?php echo (int)$u['agent_count']; ?></span>
                </td>
                <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <a class="btn btn-outline-primary" href="user.php?id=<?php echo (int)$u['id']; ?>" title="View Details">
                            <i class="bi bi-eye"></i>
                        </a>
                        <?php if ((int)$u['id'] !== (int)$user['id']): ?>
                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteUserModal<?php echo (int)$u['id']; ?>" title="Delete User">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php else: ?>
                        <button type="button" class="btn btn-outline-secondary" disabled title="Cannot delete own account">
                            <i class="bi bi-shield-lock"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            
            <!-- Delete User Modal -->
            <?php if ((int)$u['id'] !== (int)$user['id']): ?>
            <div class="modal fade" id="deleteUserModal<?php echo (int)$u['id']; ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title">Delete User</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="post">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                            <div class="modal-body">
                                <p>Are you sure you want to delete this user?</p>
                                <div class="alert alert-warning">
                                    <strong>User Information:</strong><br>
                                    Username: <strong><?php echo htmlspecialchars($u['username']); ?></strong><br>
                                    Role: <strong><?php echo htmlspecialchars($u['role']); ?></strong><br>
                                    Agents Mapped: <strong><?php echo (int)$u['agent_count']; ?></strong>
                                </div>
                                <p class="text-danger"><strong>Warning:</strong> This action cannot be undone.</p>
                                <ul class="text-danger small">
                                    <li>The user account will be permanently deleted</li>
                                    <li>All activity data for this user will remain in the database</li>
                                    <li>Agents mapped to this user will become unassigned</li>
                                    <?php if ($u['role'] === 'superadmin'): ?>
                                    <li class="fw-bold">You are deleting a superadmin account. Ensure at least one superadmin remains.</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-danger">Delete User</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php });


