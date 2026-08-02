<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pageTitle = 'Log';
$activeNav = 'logs';

if (is_post()) {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'clear') {
        db()->exec('DELETE FROM ' . table_name('system_logs'));
        system_log('WARNING', 'ADMIN', 'Log applicativi svuotati.');
        flash('success', 'Log svuotati.');
    }
    redirect('logs.php');
}

$level = $_GET['level'] ?? '';
$source = trim($_GET['source'] ?? '');
$where = [];
$params = [];
if (in_array($level, ['INFO', 'WARNING', 'ERROR'], true)) { $where[] = 'level = ?'; $params[] = $level; }
if ($source !== '') { $where[] = 'source LIKE ?'; $params[] = '%' . $source . '%'; }
$sql = 'SELECT l.*, ap.name AS access_point_name FROM ' . table_name('system_logs') . ' l LEFT JOIN ' . table_name('access_points') . ' ap ON ap.id=l.access_point_id';
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= ' ORDER BY l.created_at DESC, l.id DESC LIMIT 500';
$stmt = db()->prepare($sql); $stmt->execute($params); $logs = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="page-title h2 mb-1">Log applicativi</h1><p class="text-secondary mb-0">Sincronizzazioni con nuovi ingressi, attività amministrative ed errori API.</p></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="clear"><button class="btn btn-outline-danger" data-confirm="Svuotare tutti i log applicativi?"><i class="bi bi-trash"></i> Svuota log</button></form>
</div>

<div class="card mb-4"><div class="card-body p-4"><form method="get" class="row g-3 align-items-end">
    <div class="col-sm-4"><label class="form-label">Livello</label><select class="form-select" name="level"><option value="">Tutti</option><?php foreach (['INFO','WARNING','ERROR'] as $item): ?><option value="<?= $item ?>" <?= $level === $item ? 'selected' : '' ?>><?= $item ?></option><?php endforeach; ?></select></div>
    <div class="col-sm-5"><label class="form-label">Sorgente</label><input class="form-control" name="source" value="<?= e($source) ?>" placeholder="API, AUTH, ADMIN..."></div>
    <div class="col-sm-3 d-grid"><button class="btn btn-primary"><i class="bi bi-filter"></i> Filtra</button></div>
</form></div></div>

<div class="card"><div class="card-body p-0">
<?php if (!$logs): ?><div class="empty-state">Nessun log disponibile.</div><?php else: ?><div class="table-responsive"><table class="table table-hover mb-0">
<thead><tr><th>Data</th><th>Livello</th><th>Sorgente</th><th>Varco</th><th>UID</th><th>Messaggio</th></tr></thead>
<tbody><?php foreach ($logs as $log): ?><tr>
    <td class="text-nowrap"><?= e(format_date($log['created_at'])) ?></td>
    <td><span class="badge text-bg-<?= e(level_class($log['level'])) ?>"><?= e($log['level']) ?></span></td>
    <td><?= e($log['source']) ?></td>
    <td><?= e($log['access_point_name'] ?: ($log['access_point_id'] ? '#' . $log['access_point_id'] : '—')) ?></td>
    <td class="uid"><?= e($log['badge_uid'] ?: '—') ?></td>
    <td><div><?= e($log['message']) ?></div><?php if ($log['context_json']): ?><details class="small text-secondary mt-1"><summary>Dettagli</summary><code><?= e($log['context_json']) ?></code></details><?php endif; ?></td>
</tr><?php endforeach; ?></tbody>
</table></div><?php endif; ?>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
