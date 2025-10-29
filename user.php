<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/partials/layout.php';
require_login();
$pdo = db();

$userId = (int)($_GET['id'] ?? 0);
$user = $pdo->prepare('SELECT id, username FROM users WHERE id = ?');
$user->execute([$userId]);
$u = $user->fetch();
if (!$u) { http_response_code(404); echo 'User not found'; exit; }

$stmt = $pdo->prepare('SELECT 
	SUM(productive_seconds) AS prod,
	SUM(unproductive_seconds) AS unprod,
	SUM(idle_seconds) AS idle
	FROM activity WHERE user_id = ? AND DATE(start_time) = CURDATE()');
$stmt->execute([$userId]);
$agg = $stmt->fetch() ?: ['prod'=>0,'unprod'=>0,'idle'=>0];

function fmt_hms($seconds) {
	$seconds = (int)$seconds;
	$h = intdiv($seconds, 3600);
	$m = intdiv($seconds % 3600, 60);
	$s = $seconds % 60;
	return sprintf('%02dh %02dm %02ds', $h, $m, $s);
}

$lastSyncRow = $pdo->prepare('SELECT MAX(created_at) AS last_sync FROM activity WHERE user_id = ?');
$lastSyncRow->execute([$userId]);
$lastSync = $lastSyncRow->fetch();

// target seconds from settings
$set = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
$set->execute(['productive_hours_per_day_seconds']);
$targetSeconds = (int)($set->fetch()['value'] ?? 28800);

$shots = $pdo->prepare('SELECT taken_at, filename, filesize_kb FROM screenshots WHERE user_id = ? ORDER BY taken_at DESC LIMIT 20');
$shots->execute([$userId]);
$screens = $shots->fetchAll();

// Get devices for this user
$devices = $pdo->prepare('
	SELECT d.id, d.device_name, d.device_type, d.vendor_id, d.product_id,
	       d.first_seen, d.last_seen, d.is_blocked, d.is_allowed,
	       m.machine_id AS machine_identifier, m.hostname
	FROM devices d
	LEFT JOIN machines m ON m.id = d.machine_id
	WHERE d.user_id = ?
	ORDER BY d.last_seen DESC
	LIMIT 50
');
$devices->execute([$userId]);
$userDevices = $devices->fetchAll();

render_layout('User Detail', function() use ($u, $lastSync, $targetSeconds, $agg, $screens, $userDevices) { ?>
    <h5>User: <?php echo htmlspecialchars($u['username']); ?></h5>
    <div class="mb-3 small text-muted">Last sync: <?php echo htmlspecialchars($lastSync['last_sync'] ?? 'N/A'); ?></div>
    <div class="row g-3">
        <div class="col-md-6">
            <div class="card"><div class="card-body">
                <h6>Today Productivity</h6>
                <div class="small text-muted">Target: <?php echo round($targetSeconds/3600,1); ?> h</div>
                <div class="row text-center">
                    <div class="col">
                        <div class="small text-muted">Productive</div>
                        <div class="fw-bold"><?php echo fmt_hms($agg['prod'] ?? 0); ?></div>
                    </div>
                    <div class="col">
                        <div class="small text-muted">Unproductive</div>
                        <div class="fw-bold"><?php echo fmt_hms($agg['unprod'] ?? 0); ?></div>
                    </div>
                    <div class="col">
                        <div class="small text-muted">Idle</div>
                        <div class="fw-bold"><?php echo fmt_hms($agg['idle'] ?? 0); ?></div>
                    </div>
                </div>
                <canvas id="activityChart" height="140"></canvas>
            </div></div>
        </div>
        <div class="col-md-6">
            <div class="card"><div class="card-body"><h6>Screenshots (latest)</h6>
                <div style="max-height: 400px; overflow-y: auto; overflow-x: hidden;">
                    <div class="row g-2">
                        <?php foreach ($screens as $s): ?>
                        <div class="col-6">
                            <div class="border rounded p-1 mb-2">
                                <div class="small text-muted"><?php echo htmlspecialchars($s['taken_at']); ?> (<?php echo (int)$s['filesize_kb']; ?> KB)</div>
                                <img src="<?php echo 'storage/screenshots/' . htmlspecialchars($s['filename']); ?>" class="img-fluid" style="max-height: 150px; object-fit: contain;" />
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div></div>
        </div>
    </div>
    
    <!-- Devices Section -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">External Devices</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($userDevices)): ?>
                        <p class="text-muted">No external devices detected for this user.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Device Name</th>
                                        <th>Type</th>
                                        <th>Machine</th>
                                        <th>First Seen</th>
                                        <th>Last Seen</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($userDevices as $dev): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($dev['device_name']); ?></strong></td>
                                        <td><span class="badge bg-info"><?php echo htmlspecialchars($dev['device_type']); ?></span></td>
                                        <td><small><?php echo htmlspecialchars($dev['machine_identifier'] ?: $dev['hostname'] ?: '-'); ?></small></td>
                                        <td><?php echo htmlspecialchars($dev['first_seen']); ?></td>
                                        <td><?php echo htmlspecialchars($dev['last_seen']); ?></td>
                                        <td>
                                            <?php if ($dev['is_blocked']): ?>
                                                <span class="badge bg-danger">Blocked</span>
                                            <?php elseif ($dev['is_allowed']): ?>
                                                <span class="badge bg-success">Allowed</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    const ctx = document.getElementById('activityChart');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Productive','Unproductive','Idle'],
            datasets: [{
                label: 'Seconds',
                data: [<?php echo (int)$agg['prod']; ?>, <?php echo (int)$agg['unprod']; ?>, <?php echo (int)$agg['idle']; ?>],
                backgroundColor: ['#28a745','#dc3545','#6c757d']
            }]
        }
    });
    </script>
<?php });


