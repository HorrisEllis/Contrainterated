<?php
/** GameErrorGate — turn_failed → http_response 422 with an in-world narrative. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('turn_failed');

function failed(string $narrative, string $error, array $suggested): array
{
    return [[
        'type' => 'http_response',
        'data' => ['status' => 422, 'body' => [
            'success' => false,
            'data' => ['narrative' => $narrative, 'suggestedActions' => $suggested, 'player' => [], 'turnCount' => 0, 'currentZone' => '', 'lastSaved' => null],
            'error' => $error,
            'meta' => ['tokensUsed' => 0, 'turnTokens' => 0],
        ]],
    ]];
}

test('accepted: known code → its narrative, hint becomes the suggestion', function () use ($g) {
    assertEqual(run($g, ['code' => 'AI_TIMEOUT', 'message' => 'timed out', 'hint' => 'Try your action again']),
        failed('A strange interference disrupts your thoughts. Try again in a moment.', 'timed out', ['Try your action again']));
});

test('accepted: unknown code → generic narrative quoting the message', function () use ($g) {
    assertEqual(run($g, ['code' => 'AI_REQUEST_FAILED', 'message' => 'cURL error: timeout', 'hint' => 'Try your action again']),
        failed('Something unexpected happens. (cURL error: timeout)', 'cURL error: timeout', ['Try your action again']));
});

test('accepted: no hint → no suggestions', function () use ($g) {
    assertEqual(run($g, ['code' => 'GAME_NOT_FOUND', 'message' => 'Game not found: g1', 'hint' => null]),
        failed('You feel disoriented, as if waking from a dream within a dream.', 'Game not found: g1', []));
});

exit(report('turn_failed') === 0 ? 0 : 1);
