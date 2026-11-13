<?php
/** GameStateGate — game_load_read → http_response 200 with the game; rejects missing/unreadable/corrupt with 404. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('game_load_read');

$state = [
    'zoneDescription' => 'The bridge hums.', 'currentZone' => 'bridge', 'turnCount' => 4,
    'player' => ['name' => 'Ann', 'inventory' => ['torch']],
    'entities' => [
        ['id' => 'a', 'name' => 'Captain Chair', 'present' => true],
        ['id' => 'b', 'name' => 'Hidden Panel', 'present' => false],
        ['id' => 'c', 'name' => 'Third'],
    ],
];

function ok(array $data): array
{
    return [['type' => 'http_response', 'data' => ['status' => 200, 'body' => ['success' => true, 'data' => $data]]]];
}

function notFound(string $msg): array
{
    return [['type' => 'http_response', 'data' => ['status' => 404, 'body' => ['success' => false, 'error' => $msg]]]];
}

$summary = [
    'gameId' => 'g1', 'narrative' => 'The bridge hums.', 'currentZone' => 'bridge', 'turnCount' => 4,
    'player' => ['name' => 'Ann', 'inventory' => ['torch']],
    'suggestedActions' => ['Look around', 'Examine captain chair', 'Check inventory'],
];

test('accepted: summary (first two entities considered, hidden ones skipped)', function () use ($g, $state, $summary) {
    assertEqual(run($g, ['gameId' => 'g1', 'full' => false, 'reads' => reads(['save' => $state])]), ok($summary));
});

test('accepted: full state included on request', function () use ($g, $state, $summary) {
    assertEqual(run($g, ['gameId' => 'g1', 'full' => true, 'reads' => reads(['save' => $state])]),
        ok($summary + ['gameState' => $state]));
});

test('accepted: bare save uses defaults', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g2', 'full' => false, 'reads' => reads(['save' => []])]), ok([
        'gameId' => 'g2', 'narrative' => 'You find yourself in an unfamiliar place...', 'currentZone' => 'unknown',
        'turnCount' => 0, 'player' => [], 'suggestedActions' => ['Look around', 'Check inventory'],
    ]));
});

test('rejected (abort): save missing', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1', 'full' => false, 'reads' => reads(['save' => 'missing'])]), notFound("Game 'g1' not found"));
});

test('rejected (abort): save unreadable', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1', 'full' => false, 'reads' => reads(['save' => 'unreadable'])]), notFound('Failed to read game save file'));
});

test('rejected (abort): save corrupt', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1', 'full' => false, 'reads' => reads(['save' => 'corrupt'])]), notFound('Game save file is corrupted'));
});

exit(report('game_load_read') === 0 ? 0 : 1);
