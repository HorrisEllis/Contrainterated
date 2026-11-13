<?php
/** ZonePregenerateRequestGate — zone_pregenerate_requested → io_read {state, from, index}; rejects bad ids. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('zone_pregenerate_requested');

test('accepted: from-zone id cleaned; save, from-zone and index requested', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1', 'fromZoneId' => 'forest clearing', 'now' => 'T']), [[
        'type' => 'io_read',
        'data' => [
            'reads' => [
                'state' => ['kind' => 'save', 'gameId' => 'g1'],
                'from' => ['kind' => 'zone', 'gameId' => 'g1', 'zoneId' => 'forest_clearing'],
                'index' => ['kind' => 'zone_index', 'gameId' => 'g1'],
            ],
            'reply' => 'zone_pregenerate_loaded',
            'carry' => ['gameId' => 'g1', 'fromZoneId' => 'forest_clearing', 'now' => 'T'],
        ],
    ]]);
});

test('rejected (abort): invalid game id', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'a/b', 'fromZoneId' => 'x']), [[
        'type' => 'http_response', 'data' => ['status' => 400, 'body' => ['success' => false, 'error' => 'Invalid game ID']],
    ]]);
});

test('rejected (abort): no from-zone id', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1', 'fromZoneId' => null]), [[
        'type' => 'http_response',
        'data' => ['status' => 422, 'body' => ['success' => false, 'error' => 'Validation failed', 'errors' => ['fromZoneId' => 'From zone ID is required']]],
    ]]);
});

exit(report('zone_pregenerate_requested') === 0 ? 0 : 1);
