<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pageTitle = 'Badge';
$activeNav = 'badges';

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $uid = normalize_uid($_POST['uid'] ?? '');
            $assigneeId = (int)($_POST['assignee_id'] ?? 0);
            $counterInput = trim((string)($_POST['counter'] ?? '0'));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $accessPointIds = array_values(array_unique(array_map('intval', $_POST['access_points'] ?? [])));

            if (!$uid) {
                throw new RuntimeException('L\'UID deve contenere esattamente 8 caratteri esadecimali.');
            }
            if (!preg_match('/^\d+$/', $counterInput) || (int)$counterInput > DEVICE_COUNTER_MAX) {
                throw new RuntimeException('Contatore non valido.');
            }
            $counter = (int)$counterInput;

            if ($assigneeId > 0) {
                $stmt = db()->prepare('SELECT id FROM ' . table_name('directory_users') . ' WHERE id = ?');
                $stmt->execute([$assigneeId]);
                if (!$stmt->fetchColumn()) {
                    throw new RuntimeException('L\'assegnatario selezionato non esiste più.');
                }
            } else {
                $assigneeId = null;
            }

            db()->beginTransaction();
            $now = date('Y-m-d H:i:s');
            if ($id > 0) {
                $sql = 'UPDATE ' . table_name('badges') . '
                        SET uid = ?, assignee_id = ?, counter = ?, enabled = ?, notes = ?, updated_at = ?
                        WHERE id = ?';
                db()->prepare($sql)->execute([$uid, $assigneeId, $counter, $enabled, $notes ?: null, $now, $id]);
                $badgeId = $id;
            } else {
                $sql = 'INSERT INTO ' . table_name('badges') . '
                        (uid, assignee_id, counter, enabled, notes, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?)';
                db()->prepare($sql)->execute([$uid, $assigneeId, $counter, $enabled, $notes ?: null, $now, $now]);
                $badgeId = (int)db()->lastInsertId();
            }

            db()->prepare('DELETE FROM ' . table_name('badge_access') . ' WHERE badge_id = ?')->execute([$badgeId]);
            $assign = db()->prepare('INSERT INTO ' . table_name('badge_access') . ' (badge_id, access_point_id, created_at) VALUES (?, ?, ?)');
            foreach ($accessPointIds as $accessPointId) {
                if ($accessPointId > 0) {
                    $assign->execute([$badgeId, $accessPointId, $now]);
                }
            }
            db()->commit();

            system_log('INFO', 'ADMIN', ($id ? 'Badge aggiornato: ' : 'Badge creato: ') . $uid, null, $uid);
            flash('success', $id ? 'Badge aggiornato.' : 'Badge creato.');
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $badgeStmt = db()->prepare('SELECT uid FROM ' . table_name('badges') . ' WHERE id = ?');
            $badgeStmt->execute([$id]);
            $uid = $badgeStmt->fetchColumn();
            if ($uid === false) {
                throw new RuntimeException('Badge non trovato.');
            }

            db()->prepare('UPDATE ' . table_name('badges') . ' SET enabled = NOT enabled, updated_at = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), $id]);
            system_log('WARNING', 'ADMIN', 'Stato del badge modificato: ' . $uid, null, $uid);
            flash('success', 'Stato del badge modificato.');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $badgeStmt = db()->prepare('SELECT uid FROM ' . table_name('badges') . ' WHERE id = ?');
            $badgeStmt->execute([$id]);
            $uid = $badgeStmt->fetchColumn();
            if ($uid === false) {
                throw new RuntimeException('Badge non trovato.');
            }

            db()->prepare('DELETE FROM ' . table_name('badges') . ' WHERE id = ?')->execute([$id]);
            system_log('WARNING', 'ADMIN', 'Badge eliminato: ' . $uid, null, $uid);
            flash('success', 'Badge eliminato.');
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('danger', $e->getMessage());
    }
    redirect('badges.php');
}

$devices = db()->query('SELECT id, name FROM ' . table_name('access_points') . ' ORDER BY name')->fetchAll();
$directoryUsers = db()->query(
    'SELECT id, full_name, email, phone, active
     FROM ' . table_name('directory_users') . '
     ORDER BY active DESC, full_name ASC'
)->fetchAll();

$editBadge = null;
$editAssignments = [];
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare(
        'SELECT b.*, du.full_name AS assignee_name
         FROM ' . table_name('badges') . ' b
         LEFT JOIN ' . table_name('directory_users') . ' du ON du.id = b.assignee_id
         WHERE b.id = ?'
    );
    $stmt->execute([(int)$_GET['edit']]);
    $editBadge = $stmt->fetch() ?: null;
    if ($editBadge) {
        $stmt = db()->prepare('SELECT access_point_id FROM ' . table_name('badge_access') . ' WHERE badge_id = ?');
        $stmt->execute([$editBadge['id']]);
        $editAssignments = array_map('intval', array_column($stmt->fetchAll(), 'access_point_id'));
    }
}

$badges = db()->query(
    'SELECT b.*, du.full_name AS assignee_name, du.email AS assignee_email, du.active AS assignee_active,
        (SELECT GROUP_CONCAT(ap.name ORDER BY ap.name SEPARATOR ", ")
         FROM ' . table_name('badge_access') . ' ba
         INNER JOIN ' . table_name('access_points') . ' ap ON ap.id = ba.access_point_id
         WHERE ba.badge_id = b.id) AS access_points,
        (SELECT COUNT(*) FROM ' . table_name('badge_access') . ' ba WHERE ba.badge_id = b.id) AS access_count
     FROM ' . table_name('badges') . ' b
     LEFT JOIN ' . table_name('directory_users') . ' du ON du.id = b.assignee_id
     ORDER BY b.created_at DESC'
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="page-title h2 mb-1">Badge registrati</h1><p class="text-secondary mb-0">Gestisci assegnatari, stato, contatore e autorizzazioni ai varchi.</p></div>
</div>

<div class="row g-4">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-body p-4">
                <h2 class="h5 mb-3"><?= $editBadge ? 'Modifica badge' : 'Nuovo badge' ?></h2>
                <form method="post" class="vstack gap-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int)($editBadge['id'] ?? 0) ?>">
                    <div>
                        <label class="form-label">UID (4 byte)</label>
                        <input class="form-control uid" name="uid" maxlength="11" required value="<?= e($editBadge['uid'] ?? '') ?>" placeholder="91273000">
                        <div class="form-text">8 cifre esadecimali, separatori ammessi.</div>
                    </div>
                    <div>
                        <label class="form-label">Assegnatario</label>
                        <?php if (!$directoryUsers): ?>
                            <div class="alert alert-light border mb-0 small">L'anagrafica è vuota. <a href="people.php">Crea il primo utente</a> per assegnare il badge.</div>
                        <?php else: ?>
                            <div class="vstack gap-2" data-assignee-selector>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input class="form-control" type="search" autocomplete="off" data-assignee-search placeholder="Filtra per nome, email o telefono">
                                    <button class="btn btn-outline-secondary" type="button" data-assignee-clear title="Rimuovi assegnatario"><i class="bi bi-x-lg"></i></button>
                                </div>
                                <select class="form-select" name="assignee_id" data-assignee-select>
                                    <option value="">Nessun assegnatario</option>
                                    <?php foreach ($directoryUsers as $person): ?>
                                        <option
                                            value="<?= (int)$person['id'] ?>"
                                            data-search="<?= e($person['full_name'] . ' ' . ($person['email'] ?? '') . ' ' . ($person['phone'] ?? '')) ?>"
                                            <?= (int)($editBadge['assignee_id'] ?? 0) === (int)$person['id'] ? 'selected' : '' ?>
                                        ><?= e($person['full_name']) ?><?= $person['email'] ? ' · ' . e($person['email']) : '' ?><?= !$person['active'] ? ' (disattivo)' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="small text-secondary d-none" data-assignee-empty>Nessun utente corrispondente alla ricerca.</div>
                            </div>
                            <div class="form-text">La ricerca filtra l'elenco; il valore salvato è sempre l'utente selezionato.</div>
                        <?php endif; ?>
                    </div>
                    <div><label class="form-label">Contatore</label><input class="form-control" type="number" min="0" max="<?= DEVICE_COUNTER_MAX ?>" name="counter" value="<?= e($editBadge['counter'] ?? '0') ?>"></div>
                    <div>
                        <label class="form-label">Varchi autorizzati</label>
                        <div class="border rounded-3 p-3 vstack gap-2 bg-light">
                            <?php if (!$devices): ?><span class="small text-secondary">Crea prima almeno un varco.</span><?php endif; ?>
                            <?php foreach ($devices as $device): ?>
                                <label class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" name="access_points[]" value="<?= (int)$device['id'] ?>" <?= in_array((int)$device['id'], $editAssignments, true) ? 'checked' : '' ?>>
                                    <span class="form-check-label"><?= e($device['name']) ?> <span class="text-secondary small">#<?= (int)$device['id'] ?></span></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div><label class="form-label">Note</label><textarea class="form-control" name="notes" rows="2"><?= e($editBadge['notes'] ?? '') ?></textarea></div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" id="badgeEnabled" <?= !isset($editBadge['enabled']) || $editBadge['enabled'] ? 'checked' : '' ?>><label class="form-check-label" for="badgeEnabled">Badge attivo</label></div>
                    <div class="d-flex gap-2"><button class="btn btn-primary flex-grow-1"><i class="bi bi-check2"></i> Salva</button><?php if ($editBadge): ?><a class="btn btn-outline-secondary" href="badges.php">Annulla</a><?php endif; ?></div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card">
            <div class="card-body p-0">
                <?php if (!$badges): ?>
                    <div class="empty-state"><i class="bi bi-person-vcard fs-1"></i><p class="mt-2">Nessun badge registrato.</p></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Assegnatario</th><th>UID</th><th>Contatore</th><th>Autorizzazioni</th><th>Stato</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($badges as $badge): ?>
                            <tr>
                                <td><strong><?= e($badge['assignee_name'] ?: 'Non assegnato') ?></strong><?php if ($badge['assignee_email']): ?><div class="small text-secondary"><?= e($badge['assignee_email']) ?></div><?php endif; ?></td>
                                <td class="uid"><?= e($badge['uid']) ?></td>
                                <td><?= e($badge['counter']) ?></td>
                                <td><span class="badge text-bg-light border"><?= (int)$badge['access_count'] ?></span><div class="small text-secondary text-truncate" style="max-width:220px"><?= e($badge['access_points'] ?: 'Nessun varco') ?></div></td>
                                <td><span class="badge text-bg-<?= $badge['enabled'] ? 'success' : 'secondary' ?>"><?= $badge['enabled'] ? 'Attivo' : 'Disattivo' ?></span></td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border dropdown-toggle" data-bs-toggle="dropdown">Azioni</button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item" href="badges.php?edit=<?= (int)$badge['id'] ?>"><i class="bi bi-pencil me-2"></i>Modifica</a></li>
                                            <li><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$badge['id'] ?>"><button class="dropdown-item"><i class="bi bi-power me-2"></i><?= $badge['enabled'] ? 'Disattiva' : 'Attiva' ?></button></form></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$badge['id'] ?>"><button class="dropdown-item text-danger" data-confirm="Eliminare definitivamente questo badge?"><i class="bi bi-trash me-2"></i>Elimina</button></form></li>
                                        </ul>
                                    </div>
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
<?php require __DIR__ . '/includes/footer.php'; ?>
