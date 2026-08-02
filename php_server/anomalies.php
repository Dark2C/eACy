<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pageTitle = 'Anomalie';
$activeNav = 'anomalies';

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0 && in_array($action, ['resolve', 'reopen', 'delete'], true)) {
        if ($action === 'resolve') {
            db()->prepare('UPDATE ' . table_name('anomalies') . ' SET status="RESOLVED", resolved_at=?, resolved_by=? WHERE id=?')
                ->execute([date('Y-m-d H:i:s'), current_user()['id'], $id]);
            flash('success', 'Anomalia risolta.');
        } elseif ($action === 'reopen') {
            db()->prepare('UPDATE ' . table_name('anomalies') . ' SET status="OPEN", resolved_at=NULL, resolved_by=NULL WHERE id=?')->execute([$id]);
            flash('success', 'Anomalia riaperta.');
        } else {
            db()->prepare('DELETE FROM ' . table_name('anomalies') . ' WHERE id=?')->execute([$id]);
            flash('success', 'Anomalia eliminata.');
        }
    }
    redirect('anomalies.php?status=' . urlencode($_GET['status'] ?? 'OPEN'));
}

$status = $_GET['status'] ?? 'OPEN';
if (!in_array($status, ['OPEN', 'RESOLVED', 'ALL'], true)) { $status = 'OPEN'; }
$sql = 'SELECT a.*, ap.name AS access_point_name, du.full_name AS assignee_name, u.display_name AS resolver_name
        FROM ' . table_name('anomalies') . ' a
        LEFT JOIN ' . table_name('access_points') . ' ap ON ap.id = a.access_point_id
        LEFT JOIN ' . table_name('badges') . ' b ON b.id = a.badge_id
        LEFT JOIN ' . table_name('directory_users') . ' du ON du.id = b.assignee_id
        LEFT JOIN ' . table_name('users') . ' u ON u.id = a.resolved_by';
$params = [];
if ($status !== 'ALL') { $sql .= ' WHERE a.status = ?'; $params[] = $status; }
$sql .= ' ORDER BY a.last_seen_at DESC LIMIT 500';
$stmt = db()->prepare($sql); $stmt->execute($params); $anomalies = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="page-title h2 mb-1">Anomalie</h1><p class="text-secondary mb-0">Contatori incoerenti, badge fuori sincronia e problemi rilevati dall’API.</p></div>
    <div class="btn-group"><a class="btn btn-sm btn-<?= $status === 'OPEN' ? 'primary' : 'outline-primary' ?>" href="anomalies.php?status=OPEN">Aperte</a><a class="btn btn-sm btn-<?= $status === 'RESOLVED' ? 'primary' : 'outline-primary' ?>" href="anomalies.php?status=RESOLVED">Risolte</a><a class="btn btn-sm btn-<?= $status === 'ALL' ? 'primary' : 'outline-primary' ?>" href="anomalies.php?status=ALL">Tutte</a></div>
</div>

<div class="vstack gap-3">
<?php if (!$anomalies): ?><div class="card"><div class="empty-state"><i class="bi bi-shield-check fs-1"></i><p class="mt-2">Nessuna anomalia in questa vista.</p></div></div><?php endif; ?>
<?php foreach ($anomalies as $anomaly): ?>
    <div class="card"><div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between gap-3">
            <div class="flex-grow-1">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <span class="badge text-bg-<?= e(level_class($anomaly['severity'])) ?>"><?= e($anomaly['severity']) ?></span>
                    <span class="badge text-bg-light border"><?= e($anomaly['type']) ?></span>
                    <span class="badge text-bg-<?= $anomaly['status'] === 'OPEN' ? 'warning' : 'success' ?>"><?= e($anomaly['status']) ?></span>
                </div>
                <h2 class="h5 mb-2"><?= e($anomaly['message']) ?></h2>
                <div class="row g-2 small text-secondary">
                    <div class="col-md-4"><strong>Varco:</strong> <?= e($anomaly['access_point_name'] ?: ($anomaly['access_point_id'] ? '#' . $anomaly['access_point_id'] : '—')) ?></div>
                    <div class="col-md-4"><strong>Badge:</strong> <?= e($anomaly['assignee_name'] ?: $anomaly['badge_uid'] ?: '—') ?></div>
                    <div class="col-md-4"><strong>Occorrenze:</strong> <?= (int)$anomaly['occurrences'] ?></div>
                    <div class="col-md-4"><strong>Prima:</strong> <?= e(format_date($anomaly['first_seen_at'])) ?></div>
                    <div class="col-md-4"><strong>Ultima:</strong> <?= e(format_date($anomaly['last_seen_at'])) ?></div>
                    <?php if ($anomaly['resolved_at']): ?><div class="col-md-4"><strong>Risolta:</strong> <?= e(format_date($anomaly['resolved_at'])) ?> da <?= e($anomaly['resolver_name'] ?: 'admin') ?></div><?php endif; ?>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-start">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$anomaly['id'] ?>"><input type="hidden" name="action" value="<?= $anomaly['status'] === 'OPEN' ? 'resolve' : 'reopen' ?>"><button class="btn btn-sm btn-<?= $anomaly['status'] === 'OPEN' ? 'success' : 'outline-warning' ?>"><i class="bi bi-<?= $anomaly['status'] === 'OPEN' ? 'check2' : 'arrow-counterclockwise' ?>"></i> <?= $anomaly['status'] === 'OPEN' ? 'Risolvi' : 'Riapri' ?></button></form>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$anomaly['id'] ?>"><input type="hidden" name="action" value="delete"><button class="btn btn-sm btn-outline-danger" data-confirm="Eliminare questa anomalia?"><i class="bi bi-trash"></i></button></form>
            </div>
        </div>
    </div></div>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
