<?php
/** ZoneDeleteApplyGate — zone_delete_loaded → io_write {zone: delete} → zone_file_deleted; rejects a missing zone (404). */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('zone_delete_loaded');

function deleteWrite(?array $index): array
{
    return [[
        'type' => 'io_write',
        'data' => [
            'writes' => ['zone' => ['kind' => 'zone', 'gameId' => 'g1', 'zoneId' => 'cave', 'op' => 'delete']],
            'reply' => 'zone_file_deleted',
            'carry' => ['gameId' => 'g1', 'zoneId' => 'cave', 'index' => $index],
        ],
    ]];
}

test('accepted: the file is deleted; the index without the zone rides along', function () use ($g) {
    $out = run($g, ['gameId' => 'g1', 'zoneId' => 'cave', 'reads' => reads(['zone' => ['id' => 'cave'], 'index' => ['cave' => [], 'mill' => ['name' => 'Mill']]])]);
    assertEqual($out, deleteWrite(['mill' => ['name' => 'Mill']]));
});

test('accepted: unreadable zone file still deleted; no readable index → none to update', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1', 'zoneId' => 'cave', 'reads' => reads(['zone' => 'corrupt', 'index' => 'missing'])]), deleteWrite(null));
});

test('rejected (abort): no such zone', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1', 'zoneId' => 'cave', 'reads' => reads(['zone' => 'missing', 'index' => 'missing'])]), [[
        'type' => 'http_response', 'data' => ['status' => 404, 'body' => ['success' => false, 'error' => "Zone 'cave' not found"]],
    ]]);
});

exit(report('zone_delete_loaded') === 0 ? 0 : 1);
