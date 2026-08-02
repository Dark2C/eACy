<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pageTitle = 'Varchi';
$activeNav = 'devices';

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $customId = (int)($_POST['custom_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $enabled = isset($_POST['enabled']) ? 1 : 0;

            if ($name === '') {
                throw new RuntimeException('Il nome del varco è obbligatorio.');
            }

            if ($id > 0) {
                $sql = 'UPDATE ' . table_name('access_points') . ' SET name=?, location=?, description=?, enabled=?, updated_at=? WHERE id=?';
                db()->prepare($sql)->execute([$name, $location, $description, $enabled, date('Y-m-d H:i:s'), $id]);
                system_log('INFO', 'ADMIN', 'Varco aggiornato: ' . $name, $id);
                flash('success', 'Varco aggiornato.');
            } else {
                if ($customId > 0) {
                    $sql = 'INSERT INTO ' . table_name('access_points') . ' (id, name, location, description, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)';
                    db()->prepare($sql)->execute([$customId, $name, $location, $description, $enabled, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
                    $newId = $customId;
                } else {
                    $sql = 'INSERT INTO ' . table_name('access_points') . ' (name, location, description, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)';
                    db()->prepare($sql)->execute([$name, $location, $description, $enabled, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
                    $newId = (int)db()->lastInsertId();
                }
                system_log('INFO', 'ADMIN', 'Varco creato: ' . $name, $newId);
                flash('success', 'Varco creato con ID ' . $newId . '.');
            }
        } elseif ($action === 'toggle') {
            $id = (int)$_POST['id'];
            db()->prepare('UPDATE ' . table_name('access_points') . ' SET enabled = NOT enabled, updated_at = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), $id]);
            system_log('WARNING', 'ADMIN', 'Stato del varco modificato.', $id);
            flash('success', 'Stato del varco modificato.');
        } elseif ($action === 'delete') {
            $id = (int)$_POST['id'];
            db()->prepare('DELETE FROM ' . table_name('access_points') . ' WHERE id = ?')->execute([$id]);
            system_log('WARNING', 'ADMIN', 'Varco eliminato: #' . $id);
            flash('success', 'Varco eliminato.');
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }
    redirect('devices.php');
}

$editDevice = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM ' . table_name('access_points') . ' WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editDevice = $stmt->fetch() ?: null;
}

$devices = db()->query(
    'SELECT ap.*,
        (SELECT COUNT(*) FROM ' . table_name('badge_access') . ' ba WHERE ba.access_point_id = ap.id) AS badge_count,
        (SELECT COUNT(*) FROM ' . table_name('access_events') . ' ev WHERE ev.access_point_id = ap.id) AS event_count
     FROM ' . table_name('access_points') . ' ap ORDER BY ap.id'
)->fetchAll();

$onlineSince = date('Y-m-d H:i:s', time() - 300);
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="page-title h2 mb-1">Varchi / controlli accesso</h1><p class="text-secondary mb-0">Ogni ID corrisponde al parametro usato dal firmware.</p></div>
</div>

<div class="row g-4">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-body p-4">
                <h2 class="h5 mb-3"><?= $editDevice ? 'Modifica varco' : 'Nuovo varco' ?></h2>
                <form method="post" class="vstack gap-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int)($editDevice['id'] ?? 0) ?>">
                    <?php if (!$editDevice): ?>
                    <div>
                        <label class="form-label">ID dispositivo <span class="text-secondary small">(opzionale)</span></label>
                        <input class="form-control" type="number" min="1" name="custom_id" placeholder="Automatico">
                        <div class="form-text">Puoi specificare un ID già configurato nel firmware.</div>
                    </div>
                    <?php endif; ?>
                    <div><label class="form-label">Nome</label><input class="form-control" name="name" required value="<?= e($editDevice['name'] ?? '') ?>" placeholder="Es. Ufficio"></div>
                    <div><label class="form-label">Posizione</label><input class="form-control" name="location" value="<?= e($editDevice['location'] ?? '') ?>" placeholder="Es. Ingresso principale"></div>
                    <div><label class="form-label">Descrizione</label><textarea class="form-control" name="description" rows="3"><?= e($editDevice['description'] ?? '') ?></textarea></div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="enabled" id="enabled" <?= !isset($editDevice['enabled']) || $editDevice['enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enabled">Varco attivo</label>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary flex-grow-1"><i class="bi bi-check2"></i> Salva</button>
                        <?php if ($editDevice): ?><a class="btn btn-outline-secondary" href="devices.php">Annulla</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <?php if (!$devices): ?>
            <div class="card"><div class="empty-state"><i class="bi bi-hdd-network fs-1"></i><p class="mt-2">Nessun varco configurato.</p></div></div>
        <?php else: ?>
            <div class="vstack gap-3">
            <?php foreach ($devices as $device):
                $online = $device['enabled'] && $device['last_seen_at'] && $device['last_seen_at'] >= $onlineSince;
                $endpoint = device_endpoint((int)$device['id']);
            ?>
                <div class="card">
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <h2 class="h5 mb-0"><?= e($device['name']) ?></h2>
                                    <span class="badge text-bg-light border">ID <?= (int)$device['id'] ?></span>
                                </div>
                                <div class="text-secondary small"><?= e($device['location'] ?: 'Posizione non specificata') ?></div>
                            </div>
                            <div>
                                <?php if (!$device['enabled']): ?><span class="badge text-bg-danger">Disattivato</span>
                                <?php elseif ($online): ?><span class="badge text-bg-success">Online</span>
                                <?php else: ?><span class="badge text-bg-secondary">Offline</span><?php endif; ?>
                            </div>
                        </div>

                        <div class="row g-3 my-2 small">
                            <div class="col-sm-4"><span class="text-secondary">Ultimo contatto</span><div class="fw-semibold"><?= e(relative_date($device['last_seen_at'])) ?></div></div>
                            <div class="col-sm-4"><span class="text-secondary">Badge autorizzati</span><div class="fw-semibold"><?= (int)$device['badge_count'] ?></div></div>
                            <div class="col-sm-4"><span class="text-secondary">Ingressi registrati</span><div class="fw-semibold"><?= (int)$device['event_count'] ?></div></div>
                        </div>

                        <div class="small text-secondary mb-1">Gateway da impostare sul firmware</div>
                        <div class="d-flex gap-2 align-items-stretch">
                            <div class="code-box flex-grow-1"><?= e($endpoint) ?></div>
                            <button class="btn btn-outline-primary" type="button" onclick='copyText(<?= json_encode($endpoint) ?>, this)'><i class="bi bi-copy"></i></button>
                        </div>
                        <div class="form-text">Alternativa senza rewrite: <?= e(device_endpoint((int)$device['id'], false)) ?></div>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <a class="btn btn-sm btn-outline-primary" href="devices.php?edit=<?= (int)$device['id'] ?>"><i class="bi bi-pencil"></i> Modifica</a>
                            <form method="post">
                                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$device['id'] ?>">
                                <button class="btn btn-sm btn-outline-warning"><i class="bi bi-power"></i> <?= $device['enabled'] ? 'Disattiva' : 'Attiva' ?></button>
                            </form>
                            <form method="post">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$device['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" data-confirm="Eliminare il varco e le sue associazioni? Gli eventi storici rimarranno senza collegamento."><i class="bi bi-trash"></i> Elimina</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
