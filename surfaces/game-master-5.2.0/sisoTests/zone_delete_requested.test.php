<?php
/** ZoneDeleteGate — zone_delete_requested → io_read {zone, index}; rejects bad ids. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('zone_delete_requested');

test('accepted: asks for the zone and the index', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1', 'zoneId' => 'cave']), [[
        'type' => 'io_read',
        'data' => ['reads' => ['zone' => ['kind' => 'zone', 'gameId' => 'g1', 'zoneId' => 'cave'], 'index' => ['kind' => 'zone_index', 'gameId' => 'g1']],
            'reply' => 'zone_delete_loaded', 'carry' => ['gameId' => 'g1', 'zoneId' => 'cave']],
    ]]);
});

test('rejected (abort): invalid game id', function () use ($g) {
    assertEqual(run($g, ['gameId' => '../x', 'zoneId' => 'cave']), [[
        'type' => 'http_response', 'data' => ['status' => 400, 'body' => ['success' => false, 'error' => 'Invalid game ID']],
    ]]);
});

test('rejected (abort): zone id with path characters cannot exist', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1', 'zoneId' => '../../saves/game-g1']), [[
        'type' => 'http_response', 'data' => ['status' => 404, 'body' => ['success' => false, 'error' => "Zone '../../saves/game-g1' not found"]],
    ]]);
});

exit(report('zone_delete_requested') === 0 ? 0 : 1);
