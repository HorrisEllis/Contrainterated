<?php
/** GameCreatedGate — game_created → http_response 201; rejects a failed save with 400. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('game_created');

$carry = [
    'gameId' => 'g1', 'worldTemplateId' => 'the_matrix', 'narrative' => 'Wake up.',
    'currentZone' => 'apartment', 'player' => ['name' => 'Neo'], 'suggestedActions' => ['Follow'],
];

test('accepted: save written → 201 with the new game', function () use ($g, $carry) {
    assertEqual(run($g, $carry + ['writes' => ['save' => ['ok' => true]], 'at' => 'T']), [[
        'type' => 'http_response',
        'data' => ['status' => 201, 'body' => [
            'success' => true,
            'message' => 'Game created successfully',
            'data' => [
                'gameId' => 'g1', 'worldTemplateId' => 'the_matrix', 'narrative' => 'Wake up.',
                'currentZone' => 'apartment', 'turnCount' => 0, 'player' => ['name' => 'Neo'],
                'suggestedActions' => ['Follow'],
            ],
        ]],
    ]]);
});

test('rejected (abort): save failed → 400', function () use ($g, $carry) {
    assertEqual(run($g, $carry + ['writes' => ['save' => ['ok' => false]], 'at' => 'T']), [[
        'type' => 'http_response',
        'data' => ['status' => 400, 'body' => ['success' => false, 'error' => 'Failed to save initial game state']],
    ]]);
});

exit(report('game_created') === 0 ? 0 : 1);
