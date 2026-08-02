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

function database_has_table(PDO $pdo, string $fullTableName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$fullTableName]);

    return (int)$stmt->fetchColumn() > 0;
}

function app_is_installed(): bool
{
    try {
        return database_has_table(db(), raw_table_name('app_meta'));
    } catch (Throwable $e) {
        return false;
    }
}
