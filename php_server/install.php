<?php
require_once __DIR__ . '/includes/bootstrap.php';

$error = null;
$installed = app_is_installed();

function installer_table(string $prefix, string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
        throw new RuntimeException('Il prefisso può contenere solo lettere, numeri e underscore.');
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Nome tabella non valido.');
    }

    return '`' . $prefix . $name . '`';
}

function installer_config_contents(array $database): string
{
    $value = static function ($item): string {
        return var_export($item, true);
    };

    return "<?php\n"
        . "// Configurazione principale della demo.\n"
        . "// Le credenziali MySQL vengono salvate automaticamente da install.php.\n\n"
        . "define('APP_NAME', " . $value(APP_NAME) . ");\n"
        . "define('APP_TIMEZONE', " . $value(APP_TIMEZONE) . ");\n"
        . "define('SESSION_NAME', " . $value(SESSION_NAME) . ");\n\n"
        . "// Database MySQL\n"
        . "define('DB_HOST', " . $value($database['host']) . ");\n"
        . "define('DB_NAME', " . $value($database['name']) . ");\n"
        . "define('DB_USER', " . $value($database['user']) . ");\n"
        . "define('DB_PASS', " . $value($database['pass']) . ");\n"
        . "define('DB_CHARSET', " . $value(DB_CHARSET) . ");\n\n"
        . "// Prefisso delle tabelle. Usa soltanto lettere, numeri e underscore.\n"
        . "define('TABLE_PREFIX', " . $value($database['prefix']) . ");\n\n"
        . "// Limite massimo del body Base64 ricevuto dal microcontrollore.\n"
        . "define('MAX_API_BODY_BYTES', " . (int)MAX_API_BODY_BYTES . ");\n";
}

function installer_save_config(array $database): void
{
    $path = __DIR__ . '/config.php';
    $temp = $path . '.tmp';
    $contents = installer_config_contents($database);

    if (file_put_contents($temp, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Impossibile scrivere config.php. Verifica i permessi della cartella.');
    }

    if (!@rename($temp, $path)) {
        @unlink($temp);
        throw new RuntimeException('Impossibile sostituire config.php. Verifica i permessi del file.');
    }
}

$form = [
    'db_host' => DB_HOST !== '' ? DB_HOST : 'localhost',
    'db_name' => DB_NAME,
    'db_user' => DB_USER,
    'table_prefix' => TABLE_PREFIX !== '' ? TABLE_PREFIX : 'eacy_',
    'username' => 'admin',
    'display_name' => 'Amministratore',
];

if (is_post() && !$installed) {
    verify_csrf();

    foreach (array_keys($form) as $field) {
        if (isset($_POST[$field]) && is_string($_POST[$field])) {
            $form[$field] = trim($_POST[$field]);
        }
    }

    $database = [
        'host' => $form['db_host'],
        'name' => $form['db_name'],
        'user' => $form['db_user'],
        'pass' => (string)($_POST['db_pass'] ?? ''),
        'prefix' => $form['table_prefix'],
    ];
    $password = (string)($_POST['password'] ?? '');

    if ($database['host'] === '' || $database['name'] === '' || $database['user'] === '') {
        $error = 'Inserisci host, nome e utente del database.';
    } elseif (!preg_match('/^[A-Za-z0-9_]*$/', $database['prefix'])) {
        $error = 'Il prefisso può contenere solo lettere, numeri e underscore.';
    } else {
        try {
            $pdo = create_pdo_connection(
                $database['host'],
                $database['name'],
                $database['user'],
                $database['pass'],
                DB_CHARSET
            );

            if (application_is_installed($pdo, $database['prefix'])) {
                // Utile anche quando config.php è stato perso o contiene credenziali non più valide.
                installer_save_config($database);
                $installed = true;
            } elseif ($form['username'] === '' || $form['display_name'] === '') {
                $error = 'Inserisci nome utente e nome visualizzato.';
            } elseif ($password === '') {
                $error = 'Inserisci una password per l\'amministratore.';
            } else {
                $charset = DB_CHARSET;
                $engine = ' ENGINE=InnoDB DEFAULT CHARSET=' . $charset . ' COLLATE=utf8mb4_unicode_ci';
                $table = static function (string $name) use ($database): string {
                    return installer_table($database['prefix'], $name);
                };

                $queries = [];
                $queries[] = 'CREATE TABLE IF NOT EXISTS ' . $table('users') . ' (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    username VARCHAR(64) NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    display_name VARCHAR(100) NOT NULL,
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    last_login_at DATETIME NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id), UNIQUE KEY uq_username (username)
                )' . $engine;

                $queries[] = 'CREATE TABLE IF NOT EXISTS ' . $table('directory_users') . ' (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    full_name VARCHAR(160) NOT NULL,
                    email VARCHAR(190) NULL,
                    phone VARCHAR(50) NULL,
                    notes TEXT NULL,
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_directory_name (full_name),
                    KEY idx_directory_email (email),
                    KEY idx_directory_active (active)
                )' . $engine;

                $queries[] = 'CREATE TABLE IF NOT EXISTS ' . $table('access_points') . ' (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    name VARCHAR(120) NOT NULL,
                    location VARCHAR(180) NULL,
                    description TEXT NULL,
                    last_seen_at DATETIME NULL,
                    last_sync_at DATETIME NULL,
                    last_ip VARCHAR(45) NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id), KEY idx_last_seen (last_seen_at)
                )' . $engine;

                $queries[] = 'CREATE TABLE IF NOT EXISTS ' . $table('badges') . ' (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    uid CHAR(8) NOT NULL,
                    assignee_id INT UNSIGNED NULL,
                    counter BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    enabled TINYINT(1) NOT NULL DEFAULT 1,
                    notes TEXT NULL,
                    last_seen_at DATETIME NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_uid (uid),
                    KEY idx_enabled (enabled),
                    KEY idx_assignee (assignee_id),
                    CONSTRAINT fk_badge_assignee FOREIGN KEY (assignee_id) REFERENCES ' . $table('directory_users') . ' (id) ON DELETE SET NULL
                )' . $engine;

                $queries[] = 'CREATE TABLE IF NOT EXISTS ' . $table('badge_access') . ' (
                    badge_id INT UNSIGNED NOT NULL,
                    access_point_id INT UNSIGNED NOT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (badge_id, access_point_id),
                    CONSTRAINT fk_badge_access_badge FOREIGN KEY (badge_id) REFERENCES ' . $table('badges') . ' (id) ON DELETE CASCADE,
                    CONSTRAINT fk_badge_access_point FOREIGN KEY (access_point_id) REFERENCES ' . $table('access_points') . ' (id) ON DELETE CASCADE
                )' . $engine;

                $queries[] = 'CREATE TABLE IF NOT EXISTS ' . $table('access_events') . ' (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    access_point_id INT UNSIGNED NULL,
                    badge_id INT UNSIGNED NULL,
                    badge_uid CHAR(8) NOT NULL,
                    counter BIGINT UNSIGNED NOT NULL,
                    occurred_at DATETIME NOT NULL,
                    received_at DATETIME NOT NULL,
                    result ENUM("GRANTED", "ANOMALY", "UNKNOWN") NOT NULL DEFAULT "GRANTED",
                    raw_timestamp BIGINT UNSIGNED NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_event (access_point_id, badge_uid, counter, raw_timestamp),
                    KEY idx_event_date (occurred_at),
                    KEY idx_event_uid_time (badge_uid, raw_timestamp, counter),
                    CONSTRAINT fk_event_access_point FOREIGN KEY (access_point_id) REFERENCES ' . $table('access_points') . ' (id) ON DELETE SET NULL,
                    CONSTRAINT fk_event_badge FOREIGN KEY (badge_id) REFERENCES ' . $table('badges') . ' (id) ON DELETE SET NULL
                )' . $engine;

                $queries[] = 'CREATE TABLE IF NOT EXISTS ' . $table('anomalies') . ' (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    access_point_id INT UNSIGNED NULL,
                    badge_id INT UNSIGNED NULL,
                    badge_uid CHAR(8) NULL,
                    type VARCHAR(80) NOT NULL,
                    severity ENUM("INFO", "WARNING", "CRITICAL") NOT NULL DEFAULT "WARNING",
                    message TEXT NOT NULL,
                    fingerprint CHAR(40) NOT NULL,
                    status ENUM("OPEN", "RESOLVED") NOT NULL DEFAULT "OPEN",
                    first_seen_at DATETIME NOT NULL,
                    last_seen_at DATETIME NOT NULL,
                    resolved_at DATETIME NULL,
                    resolved_by INT UNSIGNED NULL,
                    PRIMARY KEY (id), UNIQUE KEY uq_anomaly_fingerprint (fingerprint), KEY idx_status_date (status, last_seen_at),
                    CONSTRAINT fk_anomaly_access_point FOREIGN KEY (access_point_id) REFERENCES ' . $table('access_points') . ' (id) ON DELETE SET NULL,
                    CONSTRAINT fk_anomaly_badge FOREIGN KEY (badge_id) REFERENCES ' . $table('badges') . ' (id) ON DELETE SET NULL,
                    CONSTRAINT fk_anomaly_user FOREIGN KEY (resolved_by) REFERENCES ' . $table('users') . ' (id) ON DELETE SET NULL
                )' . $engine;

                $queries[] = 'CREATE TABLE IF NOT EXISTS ' . $table('system_logs') . ' (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    level ENUM("INFO", "WARNING", "ERROR") NOT NULL DEFAULT "INFO",
                    source VARCHAR(50) NOT NULL,
                    access_point_id INT UNSIGNED NULL,
                    badge_uid CHAR(8) NULL,
                    message TEXT NOT NULL,
                    context_json LONGTEXT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id), KEY idx_log_date (created_at), KEY idx_source (source),
                    CONSTRAINT fk_log_access_point FOREIGN KEY (access_point_id) REFERENCES ' . $table('access_points') . ' (id) ON DELETE SET NULL
                )' . $engine;

                foreach ($queries as $query) {
                    $pdo->exec($query);
                }

                $now = date('Y-m-d H:i:s');
                $userSql = 'INSERT INTO ' . $table('users') . '
                    (username, password_hash, display_name, active, created_at)
                    VALUES (?, ?, ?, 1, ?)
                    ON DUPLICATE KEY UPDATE
                        password_hash = VALUES(password_hash),
                        display_name = VALUES(display_name),
                        active = 1';
                $pdo->prepare($userSql)->execute([
                    $form['username'],
                    password_hash($password, PASSWORD_DEFAULT),
                    $form['display_name'],
                    $now,
                ]);

                // Salva le credenziali soltanto dopo aver verificato la connessione e creato lo schema.
                installer_save_config($database);
                $installed = true;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Installazione · <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/app.css" rel="stylesheet">
</head>
<body class="login-page d-flex align-items-center justify-content-center p-3 py-5">
<div class="card install-card p-2 p-md-4"><div class="card-body">
    <h1 class="h3 fw-bold mb-2">Installazione</h1>
    <p class="text-secondary">Configura MySQL, crea le tabelle vuote e l\'account amministratore.</p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><strong>Errore:</strong> <?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($installed): ?>
        <div class="alert alert-success">Applicazione installata correttamente.</div>
        <p class="text-secondary small">L'installer rileva le tabelle applicative e almeno un account amministrativo.</p>
        <a class="btn btn-primary w-100" href="login.php">Vai al login</a>
    <?php else: ?>
        <form method="post" class="vstack gap-4">
            <?= csrf_field() ?>

            <section>
                <h2 class="h5 mb-3">Database MySQL</h2>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Host</label>
                        <input class="form-control" name="db_host" value="<?= e($form['db_host']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nome database</label>
                        <input class="form-control" name="db_name" value="<?= e($form['db_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Utente database</label>
                        <input class="form-control" name="db_user" value="<?= e($form['db_user']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password database</label>
                        <input class="form-control" type="password" name="db_pass" autocomplete="new-password">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Prefisso tabelle</label>
                        <input class="form-control" name="table_prefix" value="<?= e($form['table_prefix']) ?>">
                    </div>
                </div>
            </section>

            <hr class="my-0">

            <section>
                <h2 class="h5 mb-3">Amministratore</h2>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nome utente</label>
                        <input class="form-control" name="username" value="<?= e($form['username']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nome visualizzato</label>
                        <input class="form-control" name="display_name" value="<?= e($form['display_name']) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Password</label>
                        <input class="form-control" type="password" name="password" autocomplete="new-password" required>
                    </div>
                </div>
            </section>

            <div class="alert alert-info mb-0 small">
                L'installazione non crea varchi, badge o associazioni predefinite.
            </div>
            <button class="btn btn-primary btn-lg">Installa applicazione</button>
        </form>
    <?php endif; ?>
</div></div>
</body>
</html>
