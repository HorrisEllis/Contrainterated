<?php
/** ZoneIndexRemoveGate — zone_file_deleted → io_write {index} → zone_deleted; rejects a failed file delete (500). */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('zone_file_deleted');

test('accepted: file gone → the index is rewritten without the zone', function () use ($g) {
    $out = run($g, ['gameId' => 'g1', 'zoneId' => 'cave', 'index' => ['mill' => ['name' => 'Mill']], 'writes' => ['zone' => ['ok' => true]], 'at' => 'T']);
    assertEqual($out, [[
        'type' => 'io_write',
        'data' => ['writes' => ['index' => ['kind' => 'zone_index', 'gameId' => 'g1', 'op' => 'put', 'content' => ['mill' => ['name' => 'Mill']]]],
            'reply' => 'zone_deleted', 'carry' => ['zoneId' => 'cave']],
    ]]);
});

test('accepted (empty fan-out): file gone, there was no readable index → nothing to rewrite', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1', 'zoneId' => 'cave', 'index' => null, 'writes' => ['zone' => ['ok' => true]], 'at' => 'T']), [[
        'type' => 'io_write',
        'data' => ['writes' => [], 'reply' => 'zone_deleted', 'carry' => ['zoneId' => 'cave']],
    ]]);
});

test('rejected (abort): the file could not be deleted — the index is left alone', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1', 'zoneId' => 'cave', 'index' => [], 'writes' => ['zone' => ['ok' => false]], 'at' => 'T']), [[
        'type' => 'http_response', 'data' => ['status' => 500, 'body' => ['success' => false, 'error' => "Failed to delete zone 'cave'"]],
    ]]);
});

exit(report('zone_file_deleted') === 0 ? 0 : 1);
