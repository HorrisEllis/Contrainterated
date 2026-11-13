<?php
/** ZoneGetResultGate — zone_get_read → http_response 200 with the zone; rejects missing (404). */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('zone_get_read');

test('accepted: zone returned', function () use ($g) {
    $zone = ['id' => 'cave', 'name' => 'Cave'];
    assertEqual(run($g, ['zoneId' => 'cave', 'reads' => reads(['zone' => $zone])]), [[
        'type' => 'http_response', 'data' => ['status' => 200, 'body' => ['success' => true, 'message' => 'Success', 'data' => $zone]],
    ]]);
});

test('rejected (abort): zone missing or corrupt', function () use ($g) {
    $nf = [['type' => 'http_response', 'data' => ['status' => 404, 'body' => ['success' => false, 'error' => "Zone 'cave' not found"]]]];
    assertEqual(run($g, ['zoneId' => 'cave', 'reads' => reads(['zone' => 'missing'])]), $nf);
    assertEqual(run($g, ['zoneId' => 'cave', 'reads' => reads(['zone' => 'corrupt'])]), $nf);
});

exit(report('zone_get_read') === 0 ? 0 : 1);
