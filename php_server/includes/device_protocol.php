<?php

/**
 * Funzioni pure per parsing, ordinamento, deduplicazione e serializzazione del protocollo.
 */

const DEVICE_COUNTER_OUT_OF_SYNC = 4294967295;
const DEVICE_COUNTER_MAX = 4294967294;

function device_uint32le(string $bytes): int
{
    if (strlen($bytes) !== 4) {
        throw new InvalidArgumentException('Sono richiesti esattamente 4 byte.');
    }

    $value = unpack('Vvalue', $bytes);
    return (int)$value['value'];
}

/**
 * Decodifica il body Base64 inviato dal lettore.
 *
 * @return array{allowed_cards: array<int,array{uid:string,counter:int}>, history: array<int,array{uid:string,counter:int,timestamp:int}>}
 */
function decode_device_payload(string $encoded): array
{
    $body = base64_decode(trim($encoded), true);
    if ($body === false || strlen($body) < 4) {
        throw new InvalidArgumentException('Invalid Base64 payload');
    }

    $allowedCardCount = device_uint32le(substr($body, 0, 4));
    $historyOffset = 4 + ($allowedCardCount * 8);

    if ($allowedCardCount > 10000 || $historyOffset > strlen($body)) {
        throw new InvalidArgumentException('Invalid allowed card count');
    }

    $historyBytes = strlen($body) - $historyOffset;
    if ($historyBytes % 12 !== 0) {
        throw new InvalidArgumentException('Invalid history payload size');
    }

    $allowedCards = [];
    for ($i = 0; $i < $allowedCardCount; $i++) {
        $offset = 4 + ($i * 8);
        $allowedCards[] = [
            'uid' => strtoupper(bin2hex(substr($body, $offset, 4))),
            'counter' => device_uint32le(substr($body, $offset + 4, 4)),
        ];
    }

    $history = [];
    $historyCount = intdiv($historyBytes, 12);
    for ($i = 0; $i < $historyCount; $i++) {
        $offset = $historyOffset + ($i * 12);
        $history[] = [
            'uid' => strtoupper(bin2hex(substr($body, $offset, 4))),
            'counter' => device_uint32le(substr($body, $offset + 4, 4)),
            // Nel database salviamo i secondi, sufficienti per ordinamento e identità.
            'timestamp' => device_uint32le(substr($body, $offset + 8, 4)),
        ];
    }

    return [
        'allowed_cards' => $allowedCards,
        'history' => $history,
    ];
}

/**
 * Ordina per timestamp, UID numerico, contatore e varco.
 * L'identità completa dell'evento è: varco, UID, contatore e timestamp.
 * _sequence rende stabile usort() anche quando tutti i campi coincidono.
 */
function protocol_history_sort(array &$history): void
{
    usort($history, static function (array $a, array $b): int {
        $comparison = ((int)$a['timestamp']) <=> ((int)$b['timestamp']);
        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = hexdec((string)$a['uid']) <=> hexdec((string)$b['uid']);
        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = ((int)$a['counter']) <=> ((int)$b['counter']);
        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = ((int)($a['ac'] ?? 0)) <=> ((int)($b['ac'] ?? 0));
        if ($comparison !== 0) {
            return $comparison;
        }

        return ((int)($a['_sequence'] ?? 0)) <=> ((int)($b['_sequence'] ?? 0));
    });
}

/**
 * Unisce lo storico e rimuove i duplicati con stesso varco, timestamp, UID e contatore.
 */
function protocol_history_merge_and_deduplicate(array $storedHistory, array $receivedHistory): array
{
    $merged = [];
    $sequence = 0;

    foreach ($storedHistory as $record) {
        $record['_sequence'] = $sequence++;
        $record['_source'] = 'stored';
        $merged[] = $record;
    }

    foreach ($receivedHistory as $record) {
        $record['_sequence'] = $sequence++;
        $record['_source'] = 'received';
        $merged[] = $record;
    }

    protocol_history_sort($merged);

    $deduplicated = [];
    $previous = null;
    foreach ($merged as $record) {
        $isDuplicate = $previous !== null
            && (int)($record['ac'] ?? 0) === (int)($previous['ac'] ?? 0)
            && $record['uid'] === $previous['uid']
            && (int)$record['counter'] === (int)$previous['counter']
            && (int)$record['timestamp'] === (int)$previous['timestamp'];

        if (!$isDuplicate) {
            $deduplicated[] = $record;
            $previous = $record;
        }
    }

    return $deduplicated;
}

/**
 * Restituisce ogni transizione in cui il contatore diminuisce per lo stesso UID.
 * La coppia previous/current consente di creare una fingerprint stabile per evento.
 *
 * @return array<int,array{uid:string,previous:array,current:array}>
 */
function protocol_non_monotonic_transitions(array $history): array
{
    $ordered = array_values($history);
    protocol_history_sort($ordered);

    $previousByUid = [];
    $transitions = [];

    foreach ($ordered as $record) {
        // Il valore sentinella è un codice di errore dell'edge, non un contatore.
        // Non deve quindi partecipare alla sequenza monotona né diventare il
        // riferimento precedente per gli eventi successivi.
        if ((int)$record['counter'] === DEVICE_COUNTER_OUT_OF_SYNC) {
            continue;
        }

        $uid = (string)$record['uid'];
        $previous = $previousByUid[$uid] ?? null;

        if ($previous !== null && (int)$record['counter'] <= (int)$previous['counter']) {
            $transitions[] = [
                'uid' => $uid,
                'previous' => $previous,
                'current' => $record,
            ];
        }

        $previousByUid[$uid] = $record;
    }

    return $transitions;
}

/**
 * Indica se il lettore può legittimamente avere una configurazione precedente
 * del badge. I timestamp hanno precisione al secondo: a parità di valore si
 * privilegia l'ipotesi di divergenza temporanea per evitare falsi positivi.
 */
function protocol_reader_may_have_stale_badge_config(?string $previousSyncAt, ?string $badgeUpdatedAt): bool
{
    if (!$previousSyncAt || !$badgeUpdatedAt) {
        return true;
    }

    $previousSyncTimestamp = strtotime($previousSyncAt);
    $badgeUpdatedTimestamp = strtotime($badgeUpdatedAt);
    if ($previousSyncTimestamp === false || $badgeUpdatedTimestamp === false) {
        return true;
    }

    return $badgeUpdatedTimestamp >= $previousSyncTimestamp;
}

/**
 * Verifica se un accesso con badge disabilitato è avvenuto dopo una
 * sincronizzazione che avrebbe già dovuto rimuovere il badge dal lettore.
 */
function protocol_disabled_badge_access_is_after_sync(
    ?string $previousSyncAt,
    ?string $badgeUpdatedAt,
    int $eventTimestamp
): bool {
    if (protocol_reader_may_have_stale_badge_config($previousSyncAt, $badgeUpdatedAt)) {
        return false;
    }

    $previousSyncTimestamp = $previousSyncAt ? strtotime($previousSyncAt) : false;
    if ($previousSyncTimestamp === false) {
        return false;
    }

    return $eventTimestamp > $previousSyncTimestamp;
}

function encode_device_cards(array $cards): string
{
    $response = '';
    foreach ($cards as $card) {
        $uidBytes = hex2bin((string)$card['uid']);
        if ($uidBytes === false || strlen($uidBytes) !== 4) {
            continue;
        }
        $counter = (int)$card['counter'];
        if ($counter < 0 || $counter > DEVICE_COUNTER_MAX) {
            continue;
        }
        $response .= $uidBytes . pack('V', $counter);
    }
    return $response;
}
