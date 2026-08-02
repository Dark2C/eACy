<?php

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $provided = $_POST['csrf_token'] ?? '';
    if (!is_string($provided) || !hash_equals(csrf_token(), $provided)) {
        http_response_code(419);
        exit('Sessione scaduta o token CSRF non valido. Torna indietro e riprova.');
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        redirect('login.php');
    }
}

function normalize_uid(string $uid): ?string
{
    $uid = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $uid));
    return preg_match('/^[0-9A-F]{8}$/', $uid) ? $uid : null;
}

function format_date(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date('d/m/Y H:i:s', $timestamp) : $date;
}

function relative_date(?string $date): string
{
    if (!$date) {
        return 'mai';
    }

    $seconds = time() - (int)strtotime($date);
    if ($seconds < 0) {
        return format_date($date);
    }
    if ($seconds < 60) {
        return $seconds . ' sec fa';
    }
    if ($seconds < 3600) {
        return floor($seconds / 60) . ' min fa';
    }
    if ($seconds < 86400) {
        return floor($seconds / 3600) . ' ore fa';
    }
    return floor($seconds / 86400) . ' gg fa';
}

function request_base_path(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = rtrim(dirname($script), '/.');
    return $dir === '' ? '' : $dir;
}

function device_endpoint(int $deviceId, bool $pretty = true): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'example.org';
    $base = request_base_path();
    $key = DEVICE_API_KEY !== '' ? '?key=' . rawurlencode(DEVICE_API_KEY) : '';

    if ($pretty) {
        return 'http://' . $host . $base . '/api/' . $deviceId . $key;
    }

    $separator = DEVICE_API_KEY !== '' ? '&' : '';
    $keyPart = DEVICE_API_KEY !== '' ? $separator . 'key=' . rawurlencode(DEVICE_API_KEY) : '';
    return 'http://' . $host . $base . '/api/device.php?id=' . $deviceId . $keyPart;
}

function system_log(string $level, string $source, string $message, ?int $accessPointId = null, ?string $badgeUid = null, array $context = []): void
{
    try {
        $sql = 'INSERT INTO ' . table_name('system_logs') .
            ' (level, source, access_point_id, badge_uid, message, context_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)';
        db()->prepare($sql)->execute([
            strtoupper($level),
            $source,
            $accessPointId,
            $badgeUid,
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        error_log('Impossibile scrivere il log: ' . $e->getMessage());
    }
}

function create_anomaly(
    string $type,
    string $severity,
    string $message,
    ?int $accessPointId = null,
    ?int $badgeId = null,
    ?string $badgeUid = null,
    array $fingerprintParts = []
): void {
    $fingerprint = sha1(implode('|', array_merge([
        $type,
        (string)$accessPointId,
        (string)$badgeUid,
    ], $fingerprintParts)));
    $now = date('Y-m-d H:i:s');

    $find = db()->prepare(
        'SELECT id FROM ' . table_name('anomalies') . ' WHERE fingerprint = ? ORDER BY id LIMIT 1 FOR UPDATE'
    );
    $find->execute([$fingerprint]);
    $existingId = $find->fetchColumn();

    if ($existingId !== false) {
        $sql = 'UPDATE ' . table_name('anomalies') . '
                SET access_point_id = ?, badge_id = ?, badge_uid = ?, type = ?, severity = ?, message = ?,
                    status = "OPEN", last_seen_at = ?, occurrences = occurrences + 1,
                    resolved_at = NULL, resolved_by = NULL
                WHERE id = ?';
        db()->prepare($sql)->execute([
            $accessPointId,
            $badgeId,
            $badgeUid,
            $type,
            strtoupper($severity),
            $message,
            $now,
            (int)$existingId,
        ]);
        return;
    }

    $sql = 'INSERT INTO ' . table_name('anomalies') . '
            (access_point_id, badge_id, badge_uid, type, severity, message, fingerprint, status,
             first_seen_at, last_seen_at, occurrences)
            VALUES (?, ?, ?, ?, ?, ?, ?, "OPEN", ?, ?, 1)';
    db()->prepare($sql)->execute([
        $accessPointId,
        $badgeId,
        $badgeUid,
        $type,
        strtoupper($severity),
        $message,
        $fingerprint,
        $now,
        $now,
    ]);
}

function level_class(string $level): string
{
    switch (strtoupper($level)) {
        case 'ERROR':
        case 'CRITICAL':
            return 'danger';
        case 'WARNING':
            return 'warning';
        case 'SUCCESS':
            return 'success';
        default:
            return 'secondary';
    }
}
