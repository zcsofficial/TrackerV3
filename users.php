<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/partials/layout.php';
require_login();
$pdo = db();

$users = $pdo->query('SELECT id, username, role, created_at FROM users ORDER BY created_at DESC')->fetchAll();

render_layout('Users', function() use ($users) { ?>
    <h5>Users</h5>
    <table class="table table-sm table-striped">
        <thead><tr><th>ID</th><th>Username</th><th>Role</th><th>Joined</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?php echo (int)$u['id']; ?></td>
                <td><?php echo htmlspecialchars($u['username']); ?></td>
                <td><?php echo htmlspecialchars($u['role']); ?></td>
                <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                <td><a class="btn btn-sm btn-primary" href="user.php?id=<?php echo (int)$u['id']; ?>">View</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php });


