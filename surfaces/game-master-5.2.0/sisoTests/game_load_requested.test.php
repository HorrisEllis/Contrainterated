<?php
/** GameLoadGate — game_load_requested → io_read {save}; rejects a missing/invalid id with 400. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('game_load_requested');

function loadRead(string $gameId, bool $full): array
{
    return [[
        'type' => 'io_read',
        'data' => ['reads' => ['save' => ['kind' => 'save', 'gameId' => $gameId]], 'reply' => 'game_load_read',
            'carry' => ['gameId' => $gameId, 'full' => $full]],
    ]];
}

function badRequest(string $msg): array
{
    return [[
        'type' => 'http_response',
        'data' => ['status' => 400, 'body' => ['success' => false, 'error' => $msg]],
    ]];
}

test('accepted: plain id, summary only', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'game-1', 'full' => null]), loadRead('game-1', false));
});

test('accepted: id is cleaned; full=true and full=1 ask for the whole state', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'ga me.1', 'full' => 'true']), loadRead('game1', true));
    assertEqual(run($g, ['gameId' => 'g', 'full' => '1']), loadRead('g', true));
});

test('rejected (abort): empty id', function () use ($g) {
    assertEqual(run($g, ['gameId' => '']), badRequest('Game ID is required'));
});

test('rejected (abort): nothing left after cleaning', function () use ($g) {
    assertEqual(run($g, ['gameId' => '../..']), badRequest('Invalid game ID'));
});

exit(report('game_load_requested') === 0 ? 0 : 1);
