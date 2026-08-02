<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pageTitle = 'Ingressi';
$activeNav = 'events';

$deviceId = (int)($_GET['device_id'] ?? 0);
$uid = normalize_uid($_GET['uid'] ?? '') ?: '';
$result = $_GET['result'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$where = [];
$params = [];
if ($deviceId > 0) { $where[] = 'e.access_point_id = ?'; $params[] = $deviceId; }
if ($uid !== '') { $where[] = 'e.badge_uid = ?'; $params[] = $uid; }
if (in_array($result, ['GRANTED', 'ANOMALY', 'UNKNOWN'], true)) { $where[] = 'e.result = ?'; $params[] = $result; }
if ($dateFrom !== '') { $where[] = 'e.occurred_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $where[] = 'e.occurred_at <= ?'; $params[] = $dateTo . ' 23:59:59'; }

$sql = 'SELECT e.*, ap.name AS access_point_name, du.full_name AS assignee_name
        FROM ' . table_name('access_events') . ' e
        LEFT JOIN ' . table_name('access_points') . ' ap ON ap.id = e.access_point_id
        LEFT JOIN ' . table_name('badges') . ' b ON b.id = e.badge_id
        LEFT JOIN ' . table_name('directory_users') . ' du ON du.id = b.assignee_id';
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= ' ORDER BY e.occurred_at DESC, e.id DESC LIMIT 500';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();
$devices = db()->query('SELECT id, name FROM ' . table_name('access_points') . ' ORDER BY name')->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="page-title h2 mb-1">Ingressi registrati</h1><p class="text-secondary mb-0">Storico ricevuto dai dispositivi, massimo 500 righe per ricerca.</p></div>
    <span class="badge text-bg-light border fs-6"><?= count($events) ?> risultati</span>
</div>

<div class="card mb-4"><div class="card-body p-4">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-sm-6 col-lg-3"><label class="form-label">Varco</label><select class="form-select" name="device_id"><option value="0">Tutti</option><?php foreach ($devices as $device): ?><option value="<?= (int)$device['id'] ?>" <?= $deviceId === (int)$device['id'] ? 'selected' : '' ?>><?= e($device['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-6 col-lg-2"><label class="form-label">UID</label><input class="form-control uid" name="uid" value="<?= e($uid) ?>" placeholder="91273000"></div>
        <div class="col-sm-6 col-lg-2"><label class="form-label">Esito</label><select class="form-select" name="result"><option value="">Tutti</option><option value="GRANTED" <?= $result === 'GRANTED' ? 'selected' : '' ?>>Consentito</option><option value="ANOMALY" <?= $result === 'ANOMALY' ? 'selected' : '' ?>>Anomalia</option><option value="UNKNOWN" <?= $result === 'UNKNOWN' ? 'selected' : '' ?>>Sconosciuto</option></select></div>
        <div class="col-sm-6 col-lg-2"><label class="form-label">Dal</label><input class="form-control" type="date" name="date_from" value="<?= e($dateFrom) ?>"></div>
        <div class="col-sm-6 col-lg-2"><label class="form-label">Al</label><input class="form-control" type="date" name="date_to" value="<?= e($dateTo) ?>"></div>
        <div class="col-lg-1 d-grid"><button class="btn btn-primary"><i class="bi bi-search"></i></button></div>
    </form>
</div></div>

<div class="card"><div class="card-body p-0">
    <?php if (!$events): ?><div class="empty-state"><i class="bi bi-door-open fs-1"></i><p class="mt-2">Nessun ingresso trovato.</p></div>
    <?php else: ?><div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr><th>Data</th><th>Varco</th><th>Badge</th><th>UID</th><th>Contatore</th><th>Esito</th></tr></thead>
        <tbody><?php foreach ($events as $event): ?><tr>
            <td class="text-nowrap"><?= e(format_date($event['occurred_at'])) ?></td>
            <td><?= e($event['access_point_name'] ?: 'Varco #' . $event['access_point_id']) ?></td>
            <td><strong><?= e($event['assignee_name'] ?: 'Non assegnato') ?></strong></td>
            <td class="uid"><?= e($event['badge_uid']) ?></td>
            <td><?= e($event['counter']) ?></td>
            <td><span class="badge text-bg-<?= $event['result'] === 'GRANTED' ? 'success' : ($event['result'] === 'ANOMALY' ? 'danger' : 'secondary') ?>"><?= e($event['result']) ?></span></td>
        </tr><?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
