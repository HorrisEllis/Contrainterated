<?php
/** GameResultGate — turn_saved → http_response 200 (lastSaved = write time, or "failed"). */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('turn_saved');
$response = ['narrative' => 'n', 'suggestedActions' => ['a'], 'player' => ['name' => 'Ann'], 'turnCount' => 5, 'currentZone' => 'bridge'];
$meta = ['tokensUsed' => 9, 'turnTokens' => 3];

function answered(array $response, $lastSaved, array $meta): array
{
    return [[
        'type' => 'http_response',
        'data' => ['status' => 200, 'body' => ['success' => true, 'data' => $response + ['lastSaved' => $lastSaved], 'error' => null, 'meta' => $meta]],
    ]];
}

test('accepted: saved — lastSaved is the write time', function () use ($g, $response, $meta) {
    assertEqual(run($g, ['response' => $response, 'meta' => $meta, 'writes' => ['save' => ['ok' => true]], 'at' => '2026-09-27T10:00:01+00:00']),
        answered($response, '2026-09-27T10:00:01+00:00', $meta));
});

test('accepted: save failed — the turn is still answered, lastSaved "failed"', function () use ($g, $response, $meta) {
    assertEqual(run($g, ['response' => $response, 'meta' => $meta, 'writes' => ['save' => ['ok' => false]], 'at' => 'T']),
        answered($response, 'failed', $meta));
});

exit(report('turn_saved') === 0 ? 0 : 1);
