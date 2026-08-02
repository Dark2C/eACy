<?php
// Endpoint PHP/MySQL per la sincronizzazione dei lettori.
// GET  -> timestamp Unix in testo.
// POST -> body Base64; risposta binaria UID(4 byte)+counter(4 byte LE).

ini_set('display_errors', '0');
ini_set('zlib.output_compression', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/device_protocol.php';

date_default_timezone_set(APP_TIMEZONE);

function api_error(int $status, string $message): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (DEVICE_API_KEY !== '') {
    $providedKey = (string)($_GET['key'] ?? '');
    if (!hash_equals(DEVICE_API_KEY, $providedKey)) {
        api_error(403, 'Invalid API key');
    }
}

// Il GET restituisce il timestamp anche se l'ID non è presente in anagrafica.
if ($method === 'GET') {
    $payload = (string)round(microtime(true));
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/plain; charset=us-ascii');
    header('Content-Length: ' . strlen($payload));
    header('Cache-Control: no-store');
    echo $payload;
    exit;
}

if ($method !== 'POST') {
    api_error(405, 'Method not allowed');
}

// L'ID del varco viene letto dal parametro della route o della query string.
$deviceId = (int)($_GET['id'] ?? 0);

try {
    $deviceStmt = db()->prepare('SELECT * FROM ' . table_name('access_points') . ' WHERE id = ? LIMIT 1');
    $deviceStmt->execute([$deviceId]);
    $device = $deviceStmt->fetch();

    // Il protocollo richiede che il varco esista; lo stato enabled resta informativo.
    if (!$device) {
        api_error(400, 'Invalid access control system id');
    }

    $declaredLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declaredLength > MAX_API_BODY_BYTES) {
        api_error(413, 'Payload too large');
    }

    $encoded = (string)file_get_contents('php://input');
    if (trim($encoded) === '' || strlen($encoded) > MAX_API_BODY_BYTES) {
        api_error(400, 'Empty or oversized payload');
    }

    try {
        $decoded = decode_device_payload($encoded);
    } catch (InvalidArgumentException $e) {
        api_error(400, $e->getMessage());
    }

    $readerAllowedCards = $decoded['allowed_cards'];
    $receivedHistory = $decoded['history'];
    foreach ($receivedHistory as &$record) {
        $record['ac'] = $deviceId;
    }
    unset($record);

    $pdo = db();
    $pdo->beginTransaction();

    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $now = date('Y-m-d H:i:s');
    $pdo->prepare(
        'UPDATE ' . table_name('access_points') . '
         SET last_seen_at = ?, last_sync_at = ?, last_ip = ?, updated_at = ? WHERE id = ?'
    )->execute([$now, $now, $ip, $now, $deviceId]);

    // I badge vengono elaborati nell'ordine di creazione.
    $badgeRows = $pdo->query('SELECT * FROM ' . table_name('badges') . ' ORDER BY id')->fetchAll();
    $badgesByUid = [];
    foreach ($badgeRows as $badge) {
        $badgesByUid[$badge['uid']] = $badge;
    }

    // Per ogni UID viene considerato il primo record ricevuto dal lettore.
    $readerCardByUid = [];
    foreach ($readerAllowedCards as $readerCard) {
        if (!array_key_exists($readerCard['uid'], $readerCardByUid)) {
            $readerCardByUid[$readerCard['uid']] = $readerCard;
        }
    }

    $updateBadgeCounter = $pdo->prepare(
        'UPDATE ' . table_name('badges') . ' SET counter = ?, last_seen_at = ?, updated_at = ? WHERE id = ?'
    );

    // Aggiorna i contatori osservati sul lettore.
    foreach ($badgeRows as $badge) {
        $readerCard = $readerCardByUid[$badge['uid']] ?? null;
        if ($readerCard === null) {
            continue;
        }

        $serverCounter = (int)$badge['counter'];
        $readerCounter = (int)$readerCard['counter'];

        if (!(int)$badge['enabled'] && $serverCounter !== $readerCounter) {
            create_anomaly(
                'DISABLED_BADGE_COUNTER_CHANGED',
                'WARNING',
                'Card counter changed without enabling the card',
                $deviceId,
                (int)$badge['id'],
                $badge['uid']
            );
            // In presenza di anomalia il contatore memorizzato non viene aggiornato.
        } else {
            $newCounter = max($serverCounter, $readerCounter);
            $updateBadgeCounter->execute([$newCounter, $now, $now, (int)$badge['id']]);
            $badgesByUid[$badge['uid']]['counter'] = $newCounter;
        }
    }

    // Carica lo storico globale degli accessi.
    $storedRows = $pdo->query(
        'SELECT id, access_point_id, badge_id, badge_uid, counter, raw_timestamp
         FROM ' . table_name('access_events') . ' ORDER BY id FOR UPDATE'
    )->fetchAll();

    $storedHistory = [];
    foreach ($storedRows as $row) {
        $storedHistory[] = [
            'id' => (int)$row['id'],
            'uid' => $row['badge_uid'],
            'counter' => (int)$row['counter'],
            'timestamp' => (int)$row['raw_timestamp'],
            'ac' => (int)$row['access_point_id'],
        ];
    }

    // Correzione intenzionale del solo refuso record.date -> record.timestamp.
    $mergedHistory = protocol_history_merge_and_deduplicate($storedHistory, $receivedHistory);

    // Se il DB proviene dalla versione precedente, elimina eventuali duplicati globali.
    $keptStoredIds = [];
    foreach ($mergedHistory as $record) {
        if (($record['_source'] ?? '') === 'stored' && isset($record['id'])) {
            $keptStoredIds[(int)$record['id']] = true;
        }
    }
    $deleteEvent = $pdo->prepare('DELETE FROM ' . table_name('access_events') . ' WHERE id = ?');
    foreach ($storedRows as $row) {
        if (!isset($keptStoredIds[(int)$row['id']])) {
            $deleteEvent->execute([(int)$row['id']]);
        }
    }

    $insertEvent = $pdo->prepare(
        'INSERT IGNORE INTO ' . table_name('access_events') . '
         (access_point_id, badge_id, badge_uid, counter, occurred_at, received_at, result, raw_timestamp)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $insertedEvents = 0;
    foreach ($mergedHistory as $record) {
        if (($record['_source'] ?? '') !== 'received') {
            continue;
        }

        $uid = $record['uid'];
        $counter = (int)$record['counter'];
        $timestamp = (int)$record['timestamp'];
        $badge = $badgesByUid[$uid] ?? null;
        $badgeId = $badge ? (int)$badge['id'] : null;
        $result = $counter === 4294967295 ? 'ANOMALY' : ($badge ? 'GRANTED' : 'UNKNOWN');

        $insertEvent->execute([
            $deviceId,
            $badgeId,
            $uid,
            $counter,
            date('Y-m-d H:i:s', $timestamp),
            $now,
            $result,
            $timestamp,
        ]);
        if ($insertEvent->rowCount() > 0) {
            $insertedEvents++;
        }
    }

    // Ricontrolla lo storico globale e aggiorna l'alert associato a ogni UID non monotono.
    foreach (protocol_non_monotonic_uids($mergedHistory) as $uid) {
        $badge = $badgesByUid[$uid] ?? null;
        create_anomaly(
            'COUNTER_NOT_MONOTONIC',
            'WARNING',
            'Card counter not monotonic increasing',
            $deviceId,
            $badge ? (int)$badge['id'] : null,
            $uid
        );
    }

    // Un badge fuori sincronia aggiorna un solo alert per varco e UID.
    $outOfSyncUids = [];
    foreach ($receivedHistory as $record) {
        if ((int)$record['counter'] === 4294967295) {
            $outOfSyncUids[$record['uid']] = true;
        }
    }
    foreach (array_keys($outOfSyncUids) as $uid) {
        $badge = $badgesByUid[$uid] ?? null;
        create_anomaly(
            'CARD_OUT_OF_SYNC',
            'CRITICAL',
            'Card counter out of sync detected from reader',
            $deviceId,
            $badge ? (int)$badge['id'] : null,
            $uid
        );
    }

    // Restituisce tutti i badge abilitati e autorizzati per il varco.
    $allowedStmt = $pdo->prepare(
        'SELECT b.uid, b.counter
         FROM ' . table_name('badges') . ' b
         INNER JOIN ' . table_name('badge_access') . ' ba ON ba.badge_id = b.id
         WHERE ba.access_point_id = ? AND b.enabled = 1
         ORDER BY b.id'
    );
    $allowedStmt->execute([$deviceId]);
    $allowedCardsForSystem = $allowedStmt->fetchAll();
    $response = encode_device_cards($allowedCardsForSystem);

    if ($insertedEvents > 0) {
        system_log('INFO', 'API', 'Sincronizzazione con nuovi eventi di accesso: ' . $insertedEvents . '.', $deviceId, null, [
            'reader_cards' => count($readerAllowedCards),
            'history_records' => count($receivedHistory),
            'new_events' => $insertedEvents,
            'response_cards' => count($allowedCardsForSystem),
            'ip' => $ip,
        ]);
    }

    $pdo->commit();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . strlen($response));
    header('Cache-Control: no-store');
    header('Content-Encoding: identity');
    echo $response;
    exit;
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    error_log('Device API error: ' . $e->getMessage());
    system_log('ERROR', 'API', 'Errore API: ' . $e->getMessage(), $deviceId ?: null);
    api_error(500, 'Internal server error');
}
