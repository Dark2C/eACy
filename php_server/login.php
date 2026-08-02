<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$error = null;
if (is_post()) {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    try {
        $stmt = db()->prepare('SELECT * FROM ' . table_name('users') . ' WHERE username = ? AND active = 1 LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'display_name' => $user['display_name'],
            ];
            db()->prepare('UPDATE ' . table_name('users') . ' SET last_login_at = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), $user['id']]);
            system_log('INFO', 'AUTH', 'Accesso amministratore eseguito.');
            redirect('index.php');
        }

        $error = 'Credenziali non valide.';
    } catch (Throwable $e) {
        $error = 'Applicazione non installata oppure database non raggiungibile.';
    }
}
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login · <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/app.css" rel="stylesheet">
</head>
<body class="login-page d-flex align-items-center justify-content-center p-3">
<div class="card login-card p-2 p-md-4">
    <div class="card-body">
        <div class="text-center mb-4">
            <div class="brand-mark mx-auto mb-3" style="width:3.5rem;height:3.5rem;font-size:1.5rem;background:#22577a"><i class="bi bi-shield-lock"></i></div>
            <h1 class="h3 fw-bold mb-1"><?= e(APP_NAME) ?></h1>
            <p class="text-secondary mb-0">Pannello amministratore</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" class="vstack gap-3">
            <?= csrf_field() ?>
            <div>
                <label class="form-label">Nome utente</label>
                <input class="form-control form-control-lg" name="username" autocomplete="username" required autofocus>
            </div>
            <div>
                <label class="form-label">Password</label>
                <input class="form-control form-control-lg" type="password" name="password" autocomplete="current-password" required>
            </div>
            <button class="btn btn-primary btn-lg w-100"><i class="bi bi-box-arrow-in-right"></i> Accedi</button>
        </form>

    </div>
</div>
</body>
</html>
