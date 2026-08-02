<?php

/**
 * Funzioni pure per parsing, ordinamento, deduplicazione e serializzazione del protocollo.
 */

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
 * Ordina per timestamp, UID numerico e contatore.
 * L'ultima chiave diventa primaria: timestamp, UID, counter.
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

        return ((int)($a['_sequence'] ?? 0)) <=> ((int)($b['_sequence'] ?? 0));
    });
}

/**
 * Unisce lo storico e rimuove i duplicati con stesso timestamp, UID e contatore.
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
 * Restituisce gli UID che presentano almeno una diminuzione del contatore
 * nello storico globale ordinato.
 */
function protocol_non_monotonic_uids(array $history): array
{
    $groups = [];
    $insertionOrder = 0;

    foreach ($history as $record) {
        $uid = (string)$record['uid'];
        $key = 'uid:' . $uid;
        if (!isset($groups[$key])) {
            $isArrayIndex = preg_match('/^(0|[1-9][0-9]*)$/', $uid) === 1
                && (int)$uid >= 0
                && (int)$uid <= 4294967294
                && (string)(int)$uid === $uid;

            $groups[$key] = [
                'uid' => $uid,
                'counters' => [],
                'is_array_index' => $isArrayIndex,
                'numeric_key' => $isArrayIndex ? (int)$uid : null,
                'insertion_order' => $insertionOrder++,
            ];
        }
        $groups[$key]['counters'][] = (int)$record['counter'];
    }

    // Le chiavi numeriche vengono ordinate prima delle altre, poi si conserva l’ordine di inserimento.
    uasort($groups, static function (array $a, array $b): int {
        if ($a['is_array_index'] && $b['is_array_index']) {
            return $a['numeric_key'] <=> $b['numeric_key'];
        }
        if ($a['is_array_index'] !== $b['is_array_index']) {
            return $a['is_array_index'] ? -1 : 1;
        }
        return $a['insertion_order'] <=> $b['insertion_order'];
    });

    $anomalousUids = [];
    foreach ($groups as $group) {
        $counters = $group['counters'];
        for ($i = 1, $count = count($counters); $i < $count; $i++) {
            if ($counters[$i] < $counters[$i - 1]) {
                $anomalousUids[] = $group['uid'];
                break;
            }
        }
    }

    return $anomalousUids;
}

function encode_device_cards(array $cards): string
{
    $response = '';
    foreach ($cards as $card) {
        $uidBytes = hex2bin((string)$card['uid']);
        if ($uidBytes === false || strlen($uidBytes) !== 4) {
            continue;
        }
        $response .= $uidBytes . pack('V', (int)$card['counter']);
    }
    return $response;
}
