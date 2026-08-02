<?php

function raw_table_name(string $name): string
{
    $prefix = TABLE_PREFIX;
    if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
        throw new RuntimeException('TABLE_PREFIX non valido.');
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Nome tabella non valido.');
    }

    return $prefix . $name;
}

function table_name(string $name): string
{
    return '`' . raw_table_name($name) . '`';
}

function create_pdo_connection(
    string $host,
    string $database,
    string $username,
    string $password,
    string $charset = 'utf8mb4'
): PDO {
    if ($host === '' || $database === '' || $username === '') {
        throw new RuntimeException('Configurazione database incompleta.');
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', $charset)) {
        throw new RuntimeException('Charset database non valido.');
    }

    $dsn = 'mysql:host=' . $host . ';dbname=' . $database . ';charset=' . $charset;

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = create_pdo_connection(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET);
    return $pdo;
}

/**
 * @param string[] $fullTableNames
 */
function database_has_tables(PDO $pdo, array $fullTableNames): bool
{
    $fullTableNames = array_values(array_unique($fullTableNames));
    if (!$fullTableNames) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($fullTableNames), '?'));
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name IN (' . $placeholders . ')'
    );
    $stmt->execute($fullTableNames);

    return (int)$stmt->fetchColumn() === count($fullTableNames);
}

function application_table_names(string $prefix): array
{
    if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
        throw new RuntimeException('Prefisso tabelle non valido.');
    }

    return array_map(static function (string $name) use ($prefix): string {
        return $prefix . $name;
    }, [
        'users',
        'directory_users',
        'access_points',
        'badges',
        'badge_access',
        'access_events',
        'anomalies',
        'system_logs',
    ]);
}

function application_is_installed(PDO $pdo, string $prefix): bool
{
    if (!database_has_tables($pdo, application_table_names($prefix))) {
        return false;
    }

    $usersTable = '`' . $prefix . 'users`';
    return (int)$pdo->query('SELECT COUNT(*) FROM ' . $usersTable)->fetchColumn() > 0;
}

function app_is_installed(): bool
{
    try {
        return application_is_installed(db(), TABLE_PREFIX);
    } catch (Throwable $e) {
        return false;
    }
}
