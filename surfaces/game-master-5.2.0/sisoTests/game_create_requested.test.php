<?php
/** GameCreateGate — game_create_requested → io_read {template, existing}; rejects bad input with 422. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('game_create_requested');

function createRead(string $gameId, string $templateId, string $player, bool $overwrite): array
{
    return [[
        'type' => 'io_read',
        'data' => [
            'reads' => [
                'template' => ['kind' => 'template', 'templateId' => $templateId],
                'existing' => ['kind' => 'save', 'gameId' => $gameId],
            ],
            'reply' => 'game_create_loaded',
            'carry' => [
                'gameId' => $gameId,
                'worldTemplateId' => $templateId,
                'playerName' => $player,
                'overwrite' => $overwrite,
                'now' => '2026-09-27T10:00:00+00:00',
            ],
        ],
    ]];
}

function unprocessable(array $errors): array
{
    return [[
        'type' => 'http_response',
        'data' => ['status' => 422, 'body' => ['success' => false, 'error' => 'Validation failed', 'errors' => $errors]],
    ]];
}

$now = '2026-09-27T10:00:00+00:00';

test('accepted: explicit id, template and name are cleaned and requested', function () use ($g, $now) {
    $out = run($g, [
        'worldTemplateId' => 'derelict_spaceship', 'playerName' => ' Cap<tain> ',
        'gameId' => 'my game/../1', 'overwrite' => 1, 'now' => $now, 'nonce' => 'n',
    ]);
    assertEqual($out, createRead('mygame1', 'derelict_spaceship', 'Captain', true));
});

test('accepted: defaults — template "default", player "Adventurer", id from the nonce', function () use ($g, $now) {
    // md5('abc') = 900150983cd24fb0d6963f7d28e17f72 (RFC 1321 test suite value)
    $out = run($g, ['now' => $now, 'nonce' => 'abc']);
    assertEqual($out, createRead('game-900150983cd2', 'default', 'Adventurer', false));
});

test('rejected (abort): player name too long', function () use ($g, $now) {
    assertEqual(run($g, ['playerName' => str_repeat('x', 51), 'now' => $now, 'nonce' => 'n']),
        unprocessable(['playerName' => 'Player name must be 1-50 characters']));
});

test('rejected (abort): player name not a string', function () use ($g, $now) {
    assertEqual(run($g, ['playerName' => ['x'], 'now' => $now, 'nonce' => 'n']),
        unprocessable(['playerName' => 'Player name must be 1-50 characters']));
});

test('rejected (abort): game id not a string', function () use ($g, $now) {
    assertEqual(run($g, ['gameId' => 12, 'now' => $now, 'nonce' => 'n']),
        unprocessable(['gameId' => 'Game ID must be a string']));
});

test('rejected (abort): game id empty after cleaning', function () use ($g, $now) {
    assertEqual(run($g, ['gameId' => '../', 'now' => $now, 'nonce' => 'n']),
        unprocessable(['gameId' => 'Game ID must be 1-100 alphanumeric characters']));
});

exit(report('game_create_requested') === 0 ? 0 : 1);
