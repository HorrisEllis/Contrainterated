<?php
/** ZoneListGate — zone_list_requested → io_read {index}; rejects an invalid game id. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('zone_list_requested');

test('accepted: asks for the index', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1']), [[
        'type' => 'io_read',
        'data' => ['reads' => ['index' => ['kind' => 'zone_index', 'gameId' => 'g1']], 'reply' => 'zone_list_read', 'carry' => ['gameId' => 'g1']],
    ]]);
});

test('rejected (abort): invalid game id', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'a b']), [[
        'type' => 'http_response', 'data' => ['status' => 400, 'body' => ['success' => false, 'error' => 'Invalid game ID']],
    ]]);
});

exit(report('zone_list_requested') === 0 ? 0 : 1);
