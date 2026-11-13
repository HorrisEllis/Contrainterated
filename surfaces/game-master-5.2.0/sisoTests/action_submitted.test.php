<?php
/** ActionParseGate — action_submitted → io_read {save}; rejects bad input (422) and bad game ids (400). */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('action_submitted');
$now = '2026-09-27T10:00:00+00:00';

function turnRead(string $gameId, string $normalized, string $now): array
{
    return [[
        'type' => 'io_read',
        'data' => [
            'reads' => ['save' => ['kind' => 'save', 'gameId' => $gameId]],
            'reply' => 'turn_state_loaded',
            'carry' => ['gameId' => $gameId, 'action' => $normalized, 'now' => $now],
        ],
    ]];
}

function invalid(array $errors): array
{
    return [['type' => 'http_response', 'data' => ['status' => 422, 'body' => ['success' => false, 'error' => 'Validation failed', 'errors' => $errors]]]];
}

test('accepted: the action text is passed on as typed', function () use ($g, $now) {
    foreach (['examine the terminal', 'Pick Up the Key', 'dance wildly', '?'] as $action) {
        assertEqual(run($g, ['gameId' => 'g1', 'action' => $action, 'now' => $now]), turnRead('g1', $action, $now), $action);
    }
});

test('accepted: a game id of "0" is a game id', function () use ($g, $now) {
    assertEqual(run($g, ['gameId' => '0', 'action' => 'look', 'now' => $now]), turnRead('0', 'look', $now));
});

test('accepted: control characters removed and spaces collapsed', function () use ($g, $now) {
    assertEqual(run($g, ['gameId' => 'g1', 'action' => "  look\x07   at   the   map ", 'now' => $now]),
        turnRead('g1', 'look at the map', $now));
});

test('accepted: tabs count as control characters and are removed, not collapsed (pre-existing behaviour)', function () use ($g, $now) {
    assertEqual(run($g, ['gameId' => 'g1', 'action' => "look at\t\tthe map", 'now' => $now]),
        turnRead('g1', 'look atthe map', $now));
});

test('rejected (abort): no game id', function () use ($g, $now) {
    assertEqual(run($g, ['gameId' => '', 'action' => 'look', 'now' => $now]), invalid(['gameId' => 'Game ID is required']));
});

test('rejected (abort): no action', function () use ($g, $now) {
    assertEqual(run($g, ['gameId' => 'g1', 'action' => null, 'now' => $now]), invalid(['action' => 'Action is required']));
});

test('rejected (abort): action not a string', function () use ($g, $now) {
    assertEqual(run($g, ['gameId' => 'g1', 'action' => ['look'], 'now' => $now]), invalid(['action' => 'Action must be a string']));
});

test('rejected (abort): action over 500 characters', function () use ($g, $now) {
    assertEqual(run($g, ['gameId' => 'g1', 'action' => str_repeat('a', 501), 'now' => $now]),
        invalid(['action' => 'Action is too long (max 500 characters)']));
});

test('rejected (abort): action only whitespace', function () use ($g, $now) {
    assertEqual(run($g, ['gameId' => 'g1', 'action' => '   ', 'now' => $now]), invalid(['action' => 'Action cannot be empty']));
});

test('rejected (abort): game id with path characters', function () use ($g, $now) {
    assertEqual(run($g, ['gameId' => '../saves/x', 'action' => 'look', 'now' => $now]), [[
        'type' => 'http_response',
        'data' => ['status' => 400, 'body' => ['success' => false, 'error' => 'Invalid game ID']],
    ]]);
});

exit(report('action_submitted') === 0 ? 0 : 1);
