<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

$now = date('Y-m-d H:i:s');
$onlineSince = date('Y-m-d H:i:s', time() - 300);
$todayStart = date('Y-m-d 00:00:00');
$todayEnd = date('Y-m-d 23:59:59');

$metrics = [
    'devices' => (int)db()->query('SELECT COUNT(*) FROM ' . table_name('access_points'))->fetchColumn(),
    'online' => 0,
    'badges' => (int)db()->query('SELECT COUNT(*) FROM ' . table_name('badges') . ' WHERE enabled = 1')->fetchColumn(),
    'events' => 0,
    'anomalies' => (int)db()->query('SELECT COUNT(*) FROM ' . table_name('anomalies') . ' WHERE status = "OPEN"')->fetchColumn(),
];

$stmt = db()->prepare('SELECT COUNT(*) FROM ' . table_name('access_points') . ' WHERE last_seen_at >= ?');
$stmt->execute([$onlineSince]);
$metrics['online'] = (int)$stmt->fetchColumn();

$stmt = db()->prepare('SELECT COUNT(*) FROM ' . table_name('access_events') . ' WHERE occurred_at BETWEEN ? AND ?');
$stmt->execute([$todayStart, $todayEnd]);
$metrics['events'] = (int)$stmt->fetchColumn();

$recentEvents = db()->query(
    'SELECT e.*, ap.name AS access_point_name, du.full_name AS assignee_name
     FROM ' . table_name('access_events') . ' e
     LEFT JOIN ' . table_name('access_points') . ' ap ON ap.id = e.access_point_id
     LEFT JOIN ' . table_name('badges') . ' b ON b.id = e.badge_id
     LEFT JOIN ' . table_name('directory_users') . ' du ON du.id = b.assignee_id
     ORDER BY e.occurred_at DESC, e.id DESC LIMIT 8'
)->fetchAll();

$recentAnomalies = db()->query(
    'SELECT a.*, ap.name AS access_point_name
     FROM ' . table_name('anomalies') . ' a
     LEFT JOIN ' . table_name('access_points') . ' ap ON ap.id = a.access_point_id
     WHERE a.status = "OPEN"
     ORDER BY a.last_seen_at DESC LIMIT 8'
)->fetchAll();

$recentLogs = db()->query(
    'SELECT * FROM ' . table_name('system_logs') . ' ORDER BY created_at DESC, id DESC LIMIT 8'
)->fetchAll();

$activity = [];
foreach ($recentEvents as $row) {
    $activity[] = [
        'date' => $row['occurred_at'],
        'kind' => 'event',
        'title' => ($row['result'] === 'ANOMALY' ? 'Tentativo anomalo' : 'Ingresso registrato'),
        'description' => ($row['assignee_name'] ?: $row['badge_uid']) . ' · ' . ($row['access_point_name'] ?: 'Varco #' . $row['access_point_id']),
    ];
}
foreach ($recentAnomalies as $row) {
    $activity[] = [
        'date' => $row['last_seen_at'],
        'kind' => 'anomaly',
        'title' => $row['severity'] . ': ' . $row['type'],
        'description' => $row['message'],
    ];
}
foreach ($recentLogs as $row) {
    $activity[] = [
        'date' => $row['created_at'],
        'kind' => 'log',
        'title' => $row['source'],
        'description' => $row['message'],
    ];
}
usort($activity, function ($a, $b) { return strcmp($b['date'], $a['date']); });
$activity = array_slice($activity, 0, 12);

$devices = db()->query(
    'SELECT ap.*,
        (SELECT COUNT(*) FROM ' . table_name('badge_access') . ' ba WHERE ba.access_point_id = ap.id) AS badge_count
     FROM ' . table_name('access_points') . ' ap
     ORDER BY ap.name'
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="page-title h2 mb-1">Dashboard</h1>
        <p class="text-secondary mb-0">Stato generale del sistema di controllo accessi.</p>
    </div>
    <div class="text-secondary small"><i class="bi bi-clock"></i> <?= e(format_date($now)) ?></div>
</div>

<?php if ($metrics['anomalies'] > 0): ?>
<div class="alert alert-warning border-0 shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div><i class="bi bi-exclamation-triangle-fill me-2"></i><strong><?= $metrics['anomalies'] ?></strong> anomalie richiedono attenzione.</div>
    <a class="btn btn-sm btn-warning" href="anomalies.php">Apri pannello anomalie</a>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card metric-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="metric-icon"><i class="bi bi-hdd-network"></i></div>
            <div><div class="metric-value"><?= $metrics['online'] ?>/<?= $metrics['devices'] ?></div><div class="text-secondary small">Varchi online</div></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card metric-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="metric-icon"><i class="bi bi-person-vcard"></i></div>
            <div><div class="metric-value"><?= $metrics['badges'] ?></div><div class="text-secondary small">Badge attivi</div></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card metric-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="metric-icon"><i class="bi bi-door-open"></i></div>
            <div><div class="metric-value"><?= $metrics['events'] ?></div><div class="text-secondary small">Ingressi oggi</div></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card metric-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="metric-icon"><i class="bi bi-exclamation-diamond"></i></div>
            <div><div class="metric-value"><?= $metrics['anomalies'] ?></div><div class="text-secondary small">Anomalie aperte</div></div>
        </div></div>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header bg-transparent border-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                <div><h2 class="h5 mb-1">Varchi e controlli accesso</h2><p class="small text-secondary mb-0">Ultimo collegamento e badge autorizzati.</p></div>
                <a class="btn btn-sm btn-primary" href="devices.php"><i class="bi bi-plus-lg"></i> Gestisci</a>
            </div>
            <div class="card-body p-0">
                <?php if (!$devices): ?>
                    <div class="empty-state"><i class="bi bi-hdd-network fs-1"></i><p class="mt-2">Nessun varco configurato.</p></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Varco</th><th>Stato</th><th>Ultimo contatto</th><th>Badge</th></tr></thead>
                        <tbody>
                        <?php foreach ($devices as $device):
                            $online = $device['last_seen_at'] && $device['last_seen_at'] >= $onlineSince;
                        ?>
                            <tr>
                                <td><strong><?= e($device['name']) ?></strong><div class="small text-secondary"><?= e($device['location'] ?: 'ID dispositivo ' . $device['id']) ?></div></td>
                                <td>
                                    <?php if ($online): ?><span class="status-dot status-online"></span>Online
                                    <?php else: ?><span class="status-dot status-offline"></span>Offline<?php endif; ?>
                                </td>
                                <td><?= e(relative_date($device['last_seen_at'])) ?></td>
                                <td><span class="badge text-bg-light border"><?= (int)$device['badge_count'] ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header bg-transparent border-0 pt-4 px-4">
                <h2 class="h5 mb-1">Ultime attività</h2>
                <p class="small text-secondary mb-0">Ingressi, anomalie e attività applicative recenti.</p>
            </div>
            <div class="card-body">
                <?php if (!$activity): ?>
                    <div class="empty-state py-4">Nessuna attività disponibile.</div>
                <?php else: ?>
                    <?php foreach ($activity as $item):
                        $icon = $item['kind'] === 'event' ? 'door-open' : ($item['kind'] === 'anomaly' ? 'exclamation-triangle' : 'arrow-repeat');
                    ?>
                    <div class="activity-item">
                        <span class="activity-icon"><i class="bi bi-<?= e($icon) ?>"></i></span>
                        <div class="fw-semibold small"><?= e($item['title']) ?></div>
                        <div class="small text-secondary"><?= e($item['description']) ?></div>
                        <div class="small text-secondary mt-1"><?= e(relative_date($item['date'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
