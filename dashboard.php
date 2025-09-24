<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/partials/layout.php';
require_login();
$pdo = db();

$totalUsers = (int)$pdo->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
$totalMachines = (int)$pdo->query('SELECT COUNT(*) AS c FROM machines')->fetch()['c'];
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT 
	SUM(productive_seconds) AS prod,
	SUM(unproductive_seconds) AS unprod,
	SUM(idle_seconds) AS idle
	FROM activity WHERE DATE(start_time) = ?");
$stmt->execute([$today]);
$agg = $stmt->fetch() ?: ['prod'=>0,'unprod'=>0,'idle'=>0];

// Load target productive seconds from settings
$set = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = ?");
$set->execute(['productive_hours_per_day_seconds']);
$targetSeconds = (int)($set->fetch()['value'] ?? 28800);

render_layout('Dashboard', function() use ($totalUsers, $totalMachines, $agg, $targetSeconds) { ?>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card"><div class="card-body"><h6>Total Users</h6><div class="fs-3"><?php echo $totalUsers; ?></div></div></div>
        </div>
        <div class="col-md-4">
            <div class="card"><div class="card-body"><h6>Total Machines</h6><div class="fs-3"><?php echo $totalMachines; ?></div></div></div>
        </div>
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <h6>Today Activity</h6>
                <div class="small text-muted">Target: <?php echo round($targetSeconds/3600,1); ?> h</div>
                <canvas id="activityChart" height="120"></canvas>
            </div></div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    const ctx = document.getElementById('activityChart');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Productive','Unproductive','Idle'],
            datasets: [{
                data: [<?php echo (int)$agg['prod']; ?>, <?php echo (int)$agg['unprod']; ?>, <?php echo (int)$agg['idle']; ?>],
                backgroundColor: ['#28a745','#dc3545','#6c757d']
            }]
        }
    });
    </script>
<?php });