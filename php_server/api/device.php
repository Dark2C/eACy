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

/**
 * @param array<int,array{uid:string}> $records
 * @return string[]
 */
function api_unique_uids(array $records): array
{
    $uids = [];
    foreach ($records as $record) {
        $uid = (string)($record['uid'] ?? '');
        if ($uid !== '') {
            $uids[$uid] = true;
        }
    }

    return array_keys($uids);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

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
    $deviceStmt = db()->prepare('SELECT id FROM ' . table_name('access_points') . ' WHERE id = ? LIMIT 1');
    $deviceStmt->execute([$deviceId]);
    if (!$deviceStmt->fetchColumn()) {
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

    // Serializza le sincronizzazioni dello stesso varco e legge in modo coerente
    // il timestamp della sincronizzazione precedente.
    $deviceLockStmt = $pdo->prepare(
        'SELECT last_sync_at FROM ' . table_name('access_points') . ' WHERE id = ? FOR UPDATE'
    );
    $deviceLockStmt->execute([$deviceId]);
    $lockedDevice = $deviceLockStmt->fetch();
    if (!$lockedDevice) {
        $pdo->rollBack();
        api_error(400, 'Invalid access control system id');
    }
    $previousSyncAt = $lockedDevice['last_sync_at'] ?? null;

    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $now = date('Y-m-d H:i:s');
    $pdo->prepare(
        'UPDATE ' . table_name('access_points') . '
         SET last_seen_at = ?, last_sync_at = ?, last_ip = ?, updated_at = ? WHERE id = ?'
    )->execute([$now, $now, $ip, $now, $deviceId]);

    // Carica soltanto i badge citati dal payload corrente. La configurazione da
    // restituire al lettore viene selezionata separatamente a fine sync.
    $relevantUids = array_values(array_unique(array_merge(
        api_unique_uids($readerAllowedCards),
        api_unique_uids($receivedHistory)
    )));
    $badgeRows = [];
    if ($relevantUids) {
        $placeholders = implode(',', array_fill(0, count($relevantUids), '?'));
        $badgeStmt = $pdo->prepare(
            'SELECT * FROM ' . table_name('badges') . ' WHERE uid IN (' . $placeholders . ') ORDER BY id'
        );
        $badgeStmt->execute($relevantUids);
        $badgeRows = $badgeStmt->fetchAll();
    }

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

    // Il valore sentinella nella configurazione locale non è un contatore valido:
    // viene segnalato e non deve mai contaminare badges.counter.
    foreach ($readerAllowedCards as $readerCard) {
        $uid = $readerCard['uid'];
        if ((int)$readerCard['counter'] !== DEVICE_COUNTER_OUT_OF_SYNC) {
            continue;
        }

        $badge = $badgesByUid[$uid] ?? null;
        create_anomaly(
            'CARD_OUT_OF_SYNC',
            'CRITICAL',
            'Out-of-sync sentinel found in reader card configuration',
            $deviceId,
            $badge ? (int)$badge['id'] : null,
            $uid,
            ['allowed_cards', (string)DEVICE_COUNTER_OUT_OF_SYNC],
            $now
        );
    }

    $updateBadgeCounter = $pdo->prepare(
        'UPDATE ' . table_name('badges') . ' SET counter = ?, last_seen_at = ? WHERE id = ?'
    );
    $touchBadge = $pdo->prepare(
        'UPDATE ' . table_name('badges') . ' SET last_seen_at = ? WHERE id = ?'
    );

    // Aggiorna i contatori osservati senza modificare updated_at, che rappresenta
    // l'ultima variazione amministrativa della configurazione del badge.
    foreach ($badgeRows as $badge) {
        $readerCard = $readerCardByUid[$badge['uid']] ?? null;
        if ($readerCard === null) {
            continue;
        }

        $serverCounter = (int)$badge['counter'];
        $readerCounter = (int)$readerCard['counter'];
        $badgeEnabled = (int)$badge['enabled'] === 1;

        if (!$badgeEnabled && !protocol_reader_may_have_stale_badge_config(
            $previousSyncAt,
            $badge['updated_at'] ?? null
        )) {
            create_anomaly(
                'DISABLED_BADGE_STILL_CONFIGURED',
                'WARNING',
                'Disabled card still present in reader configuration after synchronization',
                $deviceId,
                (int)$badge['id'],
                $badge['uid'],
                [(string)($badge['updated_at'] ?? 'unknown')],
                $now
            );
        }

        if ($readerCounter === DEVICE_COUNTER_OUT_OF_SYNC) {
            $touchBadge->execute([$now, (int)$badge['id']]);
            continue;
        }

        // Il contatore osservato viene conservato anche per i badge disabilitati:
        // descrive lo stato reale del lettore e non modifica lo stato enabled.
        $newCounter = max($serverCounter, $readerCounter);
        $updateBadgeCounter->execute([$newCounter, $now, (int)$badge['id']]);
        $badgesByUid[$badge['uid']]['counter'] = $newCounter;
    }

    // Per il controllo di monotonicità carica solo lo storico degli UID presenti
    // nella coda ricevuta, anziché bloccare e scansionare l'intera tabella.
    $historyUids = api_unique_uids($receivedHistory);
    $storedRows = [];
    if ($historyUids) {
        $placeholders = implode(',', array_fill(0, count($historyUids), '?'));
        $storedStmt = $pdo->prepare(
            'SELECT id, access_point_id, badge_id, badge_uid, counter, raw_timestamp
             FROM ' . table_name('access_events') . '
             WHERE badge_uid IN (' . $placeholders . ')
             ORDER BY badge_uid, raw_timestamp, counter, access_point_id, id'
        );
        $storedStmt->execute($historyUids);
        $storedRows = $storedStmt->fetchAll();
    }

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

    $mergedHistory = protocol_history_merge_and_deduplicate($storedHistory, $receivedHistory);

    $insertEvent = $pdo->prepare(
        'INSERT IGNORE INTO ' . table_name('access_events') . '
         (access_point_id, badge_id, badge_uid, counter, occurred_at, received_at, result, raw_timestamp)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $insertedEvents = 0;
    $disabledAccessAfterSyncEvents = [];
    foreach ($mergedHistory as $record) {
        if (($record['_source'] ?? '') !== 'received') {
            continue;
        }

        $uid = $record['uid'];
        $counter = (int)$record['counter'];
        $timestamp = (int)$record['timestamp'];
        $badge = $badgesByUid[$uid] ?? null;
        $badgeId = $badge ? (int)$badge['id'] : null;
        // GRANTED descrive l'esito locale già prodotto dal varco. Un badge può
        // risultare disabilitato sul server solo dopo l'evento o prima della sync.
        $result = $counter === DEVICE_COUNTER_OUT_OF_SYNC ? 'ANOMALY' : ($badge ? 'GRANTED' : 'UNKNOWN');

        // Un accesso con badge disabilitato diventa una vera anomalia solo se il
        // varco aveva già completato una sync successiva alla modifica e l'evento
        // è avvenuto dopo quella sync. L'esito storico resta comunque GRANTED.
        if (
            $badge
            && (int)$badge['enabled'] !== 1
            && $counter !== DEVICE_COUNTER_OUT_OF_SYNC
            && protocol_disabled_badge_access_is_after_sync(
                $previousSyncAt,
                $badge['updated_at'] ?? null,
                $timestamp
            )
        ) {
            $disabledAccessAfterSyncEvents[] = [
                'uid' => $uid,
                'counter' => $counter,
                'timestamp' => $timestamp,
            ];
        }

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

    foreach ($disabledAccessAfterSyncEvents as $event) {
        $uid = $event['uid'];
        $badge = $badgesByUid[$uid] ?? null;
        create_anomaly(
            'DISABLED_BADGE_ACCESS_AFTER_SYNC',
            'CRITICAL',
            'Access granted with a disabled card after reader synchronization',
            $deviceId,
            $badge ? (int)$badge['id'] : null,
            $uid,
            [(string)$event['counter'], (string)$event['timestamp']],
            date('Y-m-d H:i:s', (int)$event['timestamp'])
        );
    }

    // Ogni diminuzione del contatore è un evento distinto e idempotente. Il
    // valore sentinella viene escluso dalla funzione di correlazione.
    foreach (protocol_non_monotonic_transitions($mergedHistory) as $transition) {
        $uid = $transition['uid'];
        $previous = $transition['previous'];
        $current = $transition['current'];
        $anomalyDeviceId = (int)($current['ac'] ?? $deviceId);
        $badge = $badgesByUid[$uid] ?? null;

        create_anomaly(
            'COUNTER_NOT_MONOTONIC',
            'WARNING',
            'Card counter not monotonic increasing',
            $anomalyDeviceId,
            $badge ? (int)$badge['id'] : null,
            $uid,
            [
                (string)($previous['ac'] ?? 0),
                (string)$previous['counter'],
                (string)$previous['timestamp'],
                (string)($current['ac'] ?? 0),
                (string)$current['counter'],
                (string)$current['timestamp'],
            ],
            date('Y-m-d H:i:s', (int)$current['timestamp'])
        );
    }

    // Ogni record sentinella della coda circolare identifica un evento univoco.
    foreach ($receivedHistory as $record) {
        if ((int)$record['counter'] !== DEVICE_COUNTER_OUT_OF_SYNC) {
            continue;
        }

        $uid = $record['uid'];
        $badge = $badgesByUid[$uid] ?? null;
        create_anomaly(
            'CARD_OUT_OF_SYNC',
            'CRITICAL',
            'Card counter out of sync detected from reader history',
            $deviceId,
            $badge ? (int)$badge['id'] : null,
            $uid,
            ['history', (string)$record['counter'], (string)$record['timestamp']],
            date('Y-m-d H:i:s', (int)$record['timestamp'])
        );
    }

    // Restituisce tutti i badge abilitati, autorizzati e con contatore ordinario.
    $allowedStmt = $pdo->prepare(
        'SELECT b.uid, b.counter
         FROM ' . table_name('badges') . ' b
         INNER JOIN ' . table_name('badge_access') . ' ba ON ba.badge_id = b.id
         WHERE ba.access_point_id = ?
           AND b.enabled = 1
           AND b.counter <= ' . DEVICE_COUNTER_MAX . '
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
