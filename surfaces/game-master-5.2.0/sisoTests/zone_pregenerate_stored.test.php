<?php
/** ZonePregenerateResultGate — zone_pregenerate_stored → http_response 200 summary. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('zone_pregenerate_stored');

function summary(array $data): array
{
    return [['type' => 'http_response', 'data' => ['status' => 200, 'body' => ['success' => true, 'message' => 'Success', 'data' => $data]]]];
}

test('accepted: generated, AI failures and write failures reported', function () use ($g) {
    $out = run($g, ['gameId' => 'g1', 'fromZoneId' => 'clearing', 'requested' => ['cave', 'crypt', 'mill'],
        'zones' => [['id' => 'cave', 'name' => 'Cave'], ['id' => 'mill', 'name' => 'Mill']],
        'failed' => [['zoneId' => 'crypt', 'error' => 'Rate limited (429)']],
        'writes' => ['zone:cave' => ['ok' => true], 'zone:mill' => ['ok' => false], 'index' => ['ok' => true]], 'at' => 'T']);
    assertEqual($out, summary([
        'fromZoneId' => 'clearing', 'requested' => 3,
        'generated' => [['zoneId' => 'cave', 'name' => 'Cave']],
        'errors' => [['zoneId' => 'crypt', 'error' => 'Rate limited (429)'], ['zoneId' => 'mill', 'error' => "Failed to save zone 'mill'"]],
    ]));
});

test('accepted: nothing to generate', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1', 'fromZoneId' => 'clearing', 'requested' => [], 'zones' => [], 'failed' => [], 'writes' => [], 'at' => 'T']),
        summary(['fromZoneId' => 'clearing', 'requested' => 0, 'generated' => [], 'errors' => []]));
});

exit(report('zone_pregenerate_stored') === 0 ? 0 : 1);
