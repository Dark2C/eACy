<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pageTitle = 'Anagrafica utenti';
$activeNav = 'people';

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $active = isset($_POST['active']) ? 1 : 0;

            if ($fullName === '') {
                throw new RuntimeException('Il nominativo è obbligatorio.');
            }
            $nameLength = function_exists('mb_strlen') ? mb_strlen($fullName, 'UTF-8') : strlen($fullName);
            if ($nameLength > 160) {
                throw new RuntimeException('Il nominativo non può superare 160 caratteri.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('L’indirizzo email non è valido.');
            }

            $now = date('Y-m-d H:i:s');
            if ($id > 0) {
                $sql = 'UPDATE ' . table_name('directory_users') . '
                        SET full_name = ?, email = ?, phone = ?, notes = ?, active = ?, updated_at = ?
                        WHERE id = ?';
                db()->prepare($sql)->execute([$fullName, $email ?: null, $phone ?: null, $notes ?: null, $active, $now, $id]);
                system_log('INFO', 'ADMIN', 'Utente anagrafico aggiornato: ' . $fullName);
                flash('success', 'Utente aggiornato.');
            } else {
                $sql = 'INSERT INTO ' . table_name('directory_users') . '
                        (full_name, email, phone, notes, active, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?)';
                db()->prepare($sql)->execute([$fullName, $email ?: null, $phone ?: null, $notes ?: null, $active, $now, $now]);
                system_log('INFO', 'ADMIN', 'Utente anagrafico creato: ' . $fullName);
                flash('success', 'Utente creato.');
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = db()->prepare('SELECT full_name FROM ' . table_name('directory_users') . ' WHERE id = ?');
            $stmt->execute([$id]);
            $fullName = $stmt->fetchColumn();
            if ($fullName === false) {
                throw new RuntimeException('Utente non trovato.');
            }

            db()->prepare('UPDATE ' . table_name('directory_users') . ' SET active = NOT active, updated_at = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), $id]);
            system_log('WARNING', 'ADMIN', 'Stato utente anagrafico modificato: ' . $fullName);
            flash('success', 'Stato dell’utente modificato.');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = db()->prepare('SELECT full_name FROM ' . table_name('directory_users') . ' WHERE id = ?');
            $stmt->execute([$id]);
            $fullName = $stmt->fetchColumn();
            if ($fullName === false) {
                throw new RuntimeException('Utente non trovato.');
            }

            db()->prepare('DELETE FROM ' . table_name('directory_users') . ' WHERE id = ?')->execute([$id]);
            system_log('WARNING', 'ADMIN', 'Utente anagrafico eliminato: ' . $fullName);
            flash('success', 'Utente eliminato. I badge associati risultano ora non assegnati.');
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }

    redirect('people.php');
}

$editPerson = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM ' . table_name('directory_users') . ' WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editPerson = $stmt->fetch() ?: null;
}

$query = trim((string)($_GET['q'] ?? ''));
$sql = 'SELECT du.*,
               (SELECT COUNT(*) FROM ' . table_name('badges') . ' b WHERE b.assignee_id = du.id) AS badge_count
        FROM ' . table_name('directory_users') . ' du';
$params = [];
if ($query !== '') {
    $sql .= ' WHERE du.full_name LIKE ? OR du.email LIKE ? OR du.phone LIKE ?';
    $search = '%' . $query . '%';
    $params = [$search, $search, $search];
}
$sql .= ' ORDER BY du.active DESC, du.full_name ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$people = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="page-title h2 mb-1">Anagrafica utenti</h1>
        <p class="text-secondary mb-0">Gestisci i nominativi assegnabili ai badge.</p>
    </div>
    <form method="get" class="d-flex gap-2" role="search">
        <input class="form-control" type="search" name="q" value="<?= e($query) ?>" placeholder="Cerca nome, email o telefono">
        <button class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
        <?php if ($query !== ''): ?><a class="btn btn-outline-secondary" href="people.php" title="Azzera ricerca"><i class="bi bi-x-lg"></i></a><?php endif; ?>
    </form>
</div>

<div class="row g-4">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-body p-4">
                <h2 class="h5 mb-3"><?= $editPerson ? 'Modifica utente' : 'Nuovo utente' ?></h2>
                <form method="post" class="vstack gap-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int)($editPerson['id'] ?? 0) ?>">
                    <div>
                        <label class="form-label">Nome e cognome</label>
                        <input class="form-control" name="full_name" maxlength="160" required value="<?= e($editPerson['full_name'] ?? '') ?>" placeholder="Mario Rossi">
                    </div>
                    <div>
                        <label class="form-label">Email</label>
                        <input class="form-control" type="email" name="email" maxlength="190" value="<?= e($editPerson['email'] ?? '') ?>" placeholder="mario.rossi@example.com">
                    </div>
                    <div>
                        <label class="form-label">Telefono</label>
                        <input class="form-control" name="phone" maxlength="50" value="<?= e($editPerson['phone'] ?? '') ?>" placeholder="+39 000 0000000">
                    </div>
                    <div>
                        <label class="form-label">Note</label>
                        <textarea class="form-control" name="notes" rows="3"><?= e($editPerson['notes'] ?? '') ?></textarea>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="active" id="personActive" <?= !isset($editPerson['active']) || $editPerson['active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="personActive">Utente attivo</label>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary flex-grow-1"><i class="bi bi-check2"></i> Salva</button>
                        <?php if ($editPerson): ?><a class="btn btn-outline-secondary" href="people.php">Annulla</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card">
            <div class="card-body p-0">
                <?php if (!$people): ?>
                    <div class="empty-state"><i class="bi bi-people fs-1"></i><p class="mt-2">Nessun utente trovato.</p></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Nominativo</th><th>Contatti</th><th>Badge</th><th>Stato</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($people as $person): ?>
                                <tr>
                                    <td><strong><?= e($person['full_name']) ?></strong><?php if ($person['notes']): ?><div class="small text-secondary text-truncate" style="max-width:260px"><?= e($person['notes']) ?></div><?php endif; ?></td>
                                    <td><div><?= e($person['email'] ?: '—') ?></div><div class="small text-secondary"><?= e($person['phone'] ?: '') ?></div></td>
                                    <td><span class="badge text-bg-light border"><?= (int)$person['badge_count'] ?></span></td>
                                    <td><span class="badge text-bg-<?= $person['active'] ? 'success' : 'secondary' ?>"><?= $person['active'] ? 'Attivo' : 'Disattivo' ?></span></td>
                                    <td>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-light border dropdown-toggle" data-bs-toggle="dropdown">Azioni</button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li><a class="dropdown-item" href="people.php?edit=<?= (int)$person['id'] ?>"><i class="bi bi-pencil me-2"></i>Modifica</a></li>
                                                <li><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$person['id'] ?>"><button class="dropdown-item"><i class="bi bi-power me-2"></i><?= $person['active'] ? 'Disattiva' : 'Attiva' ?></button></form></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$person['id'] ?>"><button class="dropdown-item text-danger" data-confirm="Eliminare questo utente? I badge associati diventeranno non assegnati."><i class="bi bi-trash me-2"></i>Elimina</button></form></li>
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
