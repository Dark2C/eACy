<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pageTitle = 'Profilo';
$activeNav = 'profile';

$userId = (int)(current_user()['id'] ?? 0);
$stmt = db()->prepare('SELECT id, username, password_hash, display_name, active, last_login_at, created_at FROM ' . table_name('users') . ' WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || !(int)$user['active']) {
    $_SESSION = [];
    session_destroy();
    redirect('login.php');
}

$errors = [];

if (is_post()) {
    verify_csrf();

    $username = trim((string)($_POST['username'] ?? ''));
    $displayName = trim((string)($_POST['display_name'] ?? ''));
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $newPasswordConfirm = (string)($_POST['new_password_confirm'] ?? '');

    if ($username === '') {
        $errors[] = 'Inserisci il nome utente.';
    }

    if ($displayName === '') {
        $errors[] = 'Inserisci il nome visualizzato.';
    }

    if ($newPassword !== '') {
        if (!password_verify($currentPassword, $user['password_hash'])) {
            $errors[] = 'La password attuale non è corretta.';
        }

        if ($newPassword !== $newPasswordConfirm) {
            $errors[] = 'Le nuove password non coincidono.';
        }
    }

    if (!$errors) {
        $duplicateStmt = db()->prepare('SELECT id FROM ' . table_name('users') . ' WHERE username = ? AND id <> ? LIMIT 1');
        $duplicateStmt->execute([$username, $userId]);
        if ($duplicateStmt->fetch()) {
            $errors[] = 'Il nome utente è già utilizzato.';
        }
    }

    if (!$errors) {
        $oldUsername = $user['username'];
        $oldDisplayName = $user['display_name'];
        $passwordChanged = $newPassword !== '';

        try {
            db()->beginTransaction();

            if ($passwordChanged) {
                $update = db()->prepare(
                    'UPDATE ' . table_name('users') . '
                     SET username = ?, display_name = ?, password_hash = ?
                     WHERE id = ?'
                );
                $update->execute([
                    $username,
                    $displayName,
                    password_hash($newPassword, PASSWORD_DEFAULT),
                    $userId,
                ]);
            } else {
                $update = db()->prepare(
                    'UPDATE ' . table_name('users') . '
                     SET username = ?, display_name = ?
                     WHERE id = ?'
                );
                $update->execute([$username, $displayName, $userId]);
            }

            db()->commit();

            $_SESSION['user']['username'] = $username;
            $_SESSION['user']['display_name'] = $displayName;

            system_log('INFO', 'PROFILE', 'Profilo amministratore aggiornato.', null, null, [
                'user_id' => $userId,
                'username_changed' => $oldUsername !== $username,
                'display_name_changed' => $oldDisplayName !== $displayName,
                'password_changed' => $passwordChanged,
            ]);

            flash('success', $passwordChanged
                ? 'Profilo e password aggiornati correttamente.'
                : 'Profilo aggiornato correttamente.');
            redirect('profile.php');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $errors[] = 'Impossibile aggiornare il profilo.';
            error_log('Errore aggiornamento profilo: ' . $e->getMessage());
        }
    }

    // Mantiene nel form i dati inseriti in caso di errore.
    $user['username'] = $username;
    $user['display_name'] = $displayName;
}

require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="page-title h2 mb-1">Profilo amministratore</h1>
        <p class="text-secondary mb-0">Modifica nome visualizzato, nome utente e password.</p>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger shadow-sm">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-8">
        <form method="post" class="card shadow-sm">
            <div class="card-header bg-transparent border-0 pt-4 px-4">
                <h2 class="h5 mb-1">Dati account</h2>
                <p class="small text-secondary mb-0">Il nome visualizzato appare nella barra superiore del pannello.</p>
            </div>
            <div class="card-body px-4 pb-4">
                <?= csrf_field() ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="display_name">Nome visualizzato</label>
                        <input
                            class="form-control"
                            id="display_name"
                            name="display_name"
                            value="<?= e($user['display_name']) ?>"
                            autocomplete="name"
                            required
                        >
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="username">Nome utente</label>
                        <input
                            class="form-control"
                            id="username"
                            name="username"
                            value="<?= e($user['username']) ?>"
                            autocomplete="username"
                            required
                        >
                    </div>
                </div>

                <hr class="my-4">

                <h2 class="h5 mb-1">Cambio password</h2>
                <p class="small text-secondary mb-3">Lascia vuoti i campi seguenti per mantenere la password attuale.</p>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="current_password">Password attuale</label>
                        <input
                            class="form-control"
                            type="password"
                            id="current_password"
                            name="current_password"
                            autocomplete="current-password"
                        >
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="new_password">Nuova password</label>
                        <input
                            class="form-control"
                            type="password"
                            id="new_password"
                            name="new_password"
                            autocomplete="new-password"
                        >
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="new_password_confirm">Ripeti nuova password</label>
                        <input
                            class="form-control"
                            type="password"
                            id="new_password_confirm"
                            name="new_password_confirm"
                            autocomplete="new-password"
                        >
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-4">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check-lg"></i> Salva modifiche
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div class="col-xl-4">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <div class="metric-icon"><i class="bi bi-person-circle"></i></div>
                    <div>
                        <div class="fw-semibold"><?= e($user['display_name']) ?></div>
                        <div class="small text-secondary">@<?= e($user['username']) ?></div>
                    </div>
                </div>
                <dl class="row small mb-0">
                    <dt class="col-6 text-secondary fw-normal">Ultimo accesso</dt>
                    <dd class="col-6 text-end"><?= e(format_date($user['last_login_at'])) ?></dd>
                    <dt class="col-6 text-secondary fw-normal">Account creato</dt>
                    <dd class="col-6 text-end"><?= e(format_date($user['created_at'])) ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
