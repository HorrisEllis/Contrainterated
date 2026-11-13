<?php
/** ZoneGenerateResultGate — zone_generate_stored → http_response 201; rejects failure with 500. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('zone_generate_stored');
$zone = ['id' => 'cave', 'name' => 'Cave'];
$fail = [['type' => 'http_response', 'data' => ['status' => 500, 'body' => ['success' => false, 'error' => 'Zone generation failed']]]];

test('accepted: zone written → 201 with the zone', function () use ($g, $zone) {
    assertEqual(run($g, ['gameId' => 'g1', 'fromZoneId' => null, 'requested' => ['cave'], 'zones' => [$zone], 'failed' => [],
        'writes' => ['zone:cave' => ['ok' => true], 'index' => ['ok' => true]], 'at' => 'T']), [[
        'type' => 'http_response',
        'data' => ['status' => 201, 'body' => ['success' => true, 'message' => 'Zone generated successfully', 'data' => $zone]],
    ]]);
});

test('rejected (abort): AI failed — nothing generated', function () use ($g, $fail) {
    assertEqual(run($g, ['gameId' => 'g1', 'fromZoneId' => null, 'requested' => ['cave'], 'zones' => [],
        'failed' => [['zoneId' => 'cave', 'error' => 'x']], 'writes' => [], 'at' => 'T']), $fail);
});

test('rejected (abort): zone generated but not written', function () use ($g, $zone, $fail) {
    assertEqual(run($g, ['gameId' => 'g1', 'fromZoneId' => null, 'requested' => ['cave'], 'zones' => [$zone], 'failed' => [],
        'writes' => ['zone:cave' => ['ok' => false]], 'at' => 'T']), $fail);
});

exit(report('zone_generate_stored') === 0 ? 0 : 1);
