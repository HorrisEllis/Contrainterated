<?php
/** ZoneGetGate — zone_get_requested → io_read {zone}; rejects bad ids. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('zone_get_requested');

test('accepted: asks for the zone', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1', 'zoneId' => 'cave']), [[
        'type' => 'io_read',
        'data' => ['reads' => ['zone' => ['kind' => 'zone', 'gameId' => 'g1', 'zoneId' => 'cave']], 'reply' => 'zone_get_read', 'carry' => ['zoneId' => 'cave']],
    ]]);
});

test('rejected (abort): invalid game id', function () use ($g) {
    assertEqual(run($g, ['gameId' => '..', 'zoneId' => 'cave']), [[
        'type' => 'http_response', 'data' => ['status' => 400, 'body' => ['success' => false, 'error' => 'Invalid game ID']],
    ]]);
});

test('rejected (abort): zone id with path characters cannot exist', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1', 'zoneId' => '..']), [[
        'type' => 'http_response', 'data' => ['status' => 404, 'body' => ['success' => false, 'error' => "Zone '..' not found"]],
    ]]);
});

exit(report('zone_get_requested') === 0 ? 0 : 1);
