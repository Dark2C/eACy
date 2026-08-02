<?php
require_once __DIR__ . '/../includes/device_protocol.php';

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

assert_same(
    true,
    protocol_reader_may_have_stale_badge_config(null, '2026-08-03 10:00:00'),
    'Un varco mai sincronizzato può avere una configurazione precedente.'
);
assert_same(
    true,
    protocol_reader_may_have_stale_badge_config('2026-08-03 10:00:00', '2026-08-03 10:05:00'),
    'Una modifica successiva alla precedente sync è una divergenza attesa.'
);
assert_same(
    true,
    protocol_reader_may_have_stale_badge_config('2026-08-03 10:00:00', '2026-08-03 10:00:00'),
    'A parità di secondo il controllo deve evitare falsi positivi.'
);
assert_same(
    false,
    protocol_reader_may_have_stale_badge_config('2026-08-03 10:05:00', '2026-08-03 10:00:00'),
    'Dopo una sync successiva alla modifica il badge non dovrebbe restare configurato.'
);
assert_same(
    false,
    protocol_disabled_badge_access_is_after_sync(
        '2026-08-03 10:05:00',
        '2026-08-03 10:00:00',
        strtotime('2026-08-03 10:04:59')
    ),
    'Un evento precedente alla sync non è una violazione della configurazione sincronizzata.'
);
assert_same(
    true,
    protocol_disabled_badge_access_is_after_sync(
        '2026-08-03 10:05:00',
        '2026-08-03 10:00:00',
        strtotime('2026-08-03 10:05:01')
    ),
    'Un evento successivo alla sync è una vera anomalia.'
);
assert_same(
    false,
    protocol_disabled_badge_access_is_after_sync(
        '2026-08-03 10:05:00',
        '2026-08-03 10:06:00',
        strtotime('2026-08-03 10:07:00')
    ),
    'Una modifica successiva alla sync rende attesa la divergenza locale.'
);

$deduplicated = protocol_history_merge_and_deduplicate(
    [
        ['id' => 1, 'uid' => 'AABBCCDD', 'counter' => 7, 'timestamp' => 100, 'ac' => 1],
    ],
    [
        ['uid' => 'AABBCCDD', 'counter' => 7, 'timestamp' => 100, 'ac' => 1],
        ['uid' => 'AABBCCDD', 'counter' => 7, 'timestamp' => 100, 'ac' => 2],
    ]
);
assert_same(2, count($deduplicated), 'Lo stesso evento sullo stesso varco va deduplicato, su varchi diversi no.');
assert_same('stored', $deduplicated[0]['_source'], 'Il record già persistito deve prevalere sul replay dello stesso varco.');
assert_same(2, (int)$deduplicated[1]['ac'], 'Il varco deve far parte dell\'identità dell\'evento.');
assert_same('received', $deduplicated[1]['_source'], 'L\'evento equivalente di un altro varco deve restare da inserire.');

$transitions = protocol_non_monotonic_transitions([
    ['uid' => 'AABBCCDD', 'counter' => 1, 'timestamp' => 100, 'ac' => 1],
    ['uid' => '11223344', 'counter' => 4, 'timestamp' => 105, 'ac' => 2],
    ['uid' => 'AABBCCDD', 'counter' => 3, 'timestamp' => 110, 'ac' => 1],
    ['uid' => 'AABBCCDD', 'counter' => 2, 'timestamp' => 120, 'ac' => 2],
]);
assert_same(1, count($transitions), 'Ogni diminuzione del contatore deve produrre una transizione distinta.');
assert_same('AABBCCDD', $transitions[0]['uid'], 'La transizione deve conservare l\'UID.');
assert_same(3, (int)$transitions[0]['previous']['counter'], 'La transizione deve conservare il contatore precedente.');
assert_same(2, (int)$transitions[0]['current']['counter'], 'La transizione deve conservare il contatore corrente.');
assert_same(2, (int)$transitions[0]['current']['ac'], 'Il varco dell\'evento corrente deve restare disponibile.');

$sentinelTransitions = protocol_non_monotonic_transitions([
    ['uid' => 'AABBCCDD', 'counter' => 3, 'timestamp' => 100, 'ac' => 1],
    ['uid' => 'AABBCCDD', 'counter' => DEVICE_COUNTER_OUT_OF_SYNC, 'timestamp' => 110, 'ac' => 1],
    ['uid' => 'AABBCCDD', 'counter' => 2, 'timestamp' => 120, 'ac' => 1],
]);
assert_same(1, count($sentinelTransitions), 'La sentinella non deve diventare il contatore precedente.');
assert_same(3, (int)$sentinelTransitions[0]['previous']['counter'], 'Il confronto deve saltare la sentinella.');
assert_same(2, (int)$sentinelTransitions[0]['current']['counter'], 'La diminuzione reale deve restare rilevabile.');

$onlySentinelDrop = protocol_non_monotonic_transitions([
    ['uid' => 'AABBCCDD', 'counter' => DEVICE_COUNTER_OUT_OF_SYNC, 'timestamp' => 100, 'ac' => 1],
    ['uid' => 'AABBCCDD', 'counter' => 25, 'timestamp' => 110, 'ac' => 1],
]);
assert_same([], $onlySentinelDrop, 'La sequenza sentinella → contatore ordinario non è una diminuzione reale.');

$encoded = encode_device_cards([
    ['uid' => 'AABBCCDD', 'counter' => DEVICE_COUNTER_MAX],
    ['uid' => '11223344', 'counter' => DEVICE_COUNTER_OUT_OF_SYNC],
]);
assert_same(8, strlen($encoded), 'La serializzazione deve escludere il contatore sentinella.');
assert_same('AABBCCDD', strtoupper(bin2hex(substr($encoded, 0, 4))), 'Il badge valido deve essere serializzato.');
assert_same(DEVICE_COUNTER_MAX, device_uint32le(substr($encoded, 4, 4)), 'Il massimo contatore ordinario deve essere accettato.');

fwrite(STDOUT, "OK: device protocol tests passed.\n");
