<?php
/** ZoneListResultGate — zone_list_read → http_response 200 with the index. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('zone_list_read');

function listed(array $zones): array
{
    return [['type' => 'http_response', 'data' => ['status' => 200, 'body' => ['success' => true, 'message' => 'Success',
        'data' => ['gameId' => 'g1', 'count' => count($zones), 'zones' => $zones]]]]];
}

test('accepted: index listed', function () use ($g) {
    $index = ['cave' => ['name' => 'Cave'], 'mill' => ['name' => 'Mill']];
    assertEqual(run($g, ['gameId' => 'g1', 'reads' => reads(['index' => $index])]), listed($index));
});

test('accepted: no index → empty list', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1', 'reads' => reads(['index' => 'missing'])]), listed([]));
});

exit(report('zone_list_read') === 0 ? 0 : 1);
