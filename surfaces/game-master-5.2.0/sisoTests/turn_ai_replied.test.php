<?php
/** ResponseParseGate — turn_ai_replied → io_read {save} carrying the parsed turn; rejects a failed AI call (turn_failed). */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('turn_ai_replied');
$action = 'look';
$usage = ['input' => 1200, 'output' => 300];
$defaults = ['Look around', 'Check inventory', 'Examine surroundings'];

function replied(string $content, string $action, array $usage): array
{
    return ['gameId' => 'g1', 'action' => $action, 'now' => 'T',
        'ai' => ['ok' => true, 'content' => $content, 'usage' => $usage, 'model' => 'claude-sonnet-4-20250514', 'error' => null]];
}

function applyRead(array $parsed, string $action, array $usage): array
{
    return [[
        'type' => 'io_read',
        'data' => [
            'reads' => ['save' => ['kind' => 'save', 'gameId' => 'g1']],
            'reply' => 'turn_apply_loaded',
            'carry' => ['gameId' => 'g1', 'action' => $action, 'now' => 'T', 'parsed' => $parsed,
                'usage' => $usage, 'model' => 'claude-sonnet-4-20250514'],
        ],
    ]];
}

function emptyUpdates(): array
{
    return ['entityChanges' => [], 'inventoryChanges' => [], 'statChanges' => [], 'flagChanges' => []];
}

test('accepted: plain JSON reply', function () use ($g, $action, $usage) {
    $json = json_encode([
        'narrative' => 'The terminal flickers.',
        'stateUpdates' => ['entityChanges' => [['entityId' => 'terminal', 'properties' => ['powered' => true]]],
            'inventoryChanges' => ['add:data_chip'], 'statChanges' => ['oxygen' => 80], 'flagChanges' => ['seen' => true]],
        'suggestedActions' => ['Read logs', 'Power down'],
        'profileInsight' => 'Curious',
    ]);
    assertEqual(run($g, replied($json, $action, $usage)), applyRead([
        'narrative' => 'The terminal flickers.',
        'stateUpdates' => ['entityChanges' => [['entityId' => 'terminal', 'properties' => ['powered' => true]]],
            'inventoryChanges' => ['add:data_chip'], 'statChanges' => ['oxygen' => 80], 'flagChanges' => ['seen' => true]],
        'suggestedActions' => ['Read logs', 'Power down'],
        'profileInsight' => 'Curious',
        'newPromises' => [],
    ], $action, $usage));
});

test('accepted: JSON inside a ```json block, after prose', function () use ($g, $action, $usage, $defaults) {
    $content = "Sure! Here it is:\n```json\n{\"narrative\": \"You wait.\"}\n```\nEnjoy.";
    assertEqual(run($g, replied($content, $action, $usage)), applyRead([
        'narrative' => 'You wait.', 'stateUpdates' => emptyUpdates(), 'suggestedActions' => $defaults, 'newPromises' => [],
    ], $action, $usage));
});

test('accepted: JSON object embedded in prose (brace matching skips non-narrative objects)', function () use ($g, $action, $usage, $defaults) {
    $content = 'Note {"x": 1} then {"narrative": "A door {creaks}.", "suggestedActions": ["Enter"]} end';
    assertEqual(run($g, replied($content, $action, $usage)), applyRead([
        'narrative' => 'A door {creaks}.', 'suggestedActions' => ['Enter'], 'stateUpdates' => emptyUpdates(), 'newPromises' => [],
    ], $action, $usage));
});

test('accepted: no JSON — prose becomes the narrative, markdown stripped', function () use ($g, $action, $usage, $defaults) {
    $content = "**The hatch** _opens_ slowly.\n\n# Onward";
    assertEqual(run($g, replied($content, $action, $usage)), applyRead([
        'narrative' => 'The hatch opens slowly. Onward', 'stateUpdates' => emptyUpdates(), 'suggestedActions' => $defaults, 'newPromises' => [],
    ], $action, $usage));
});

test('accepted: sanitising — tags stripped, bad changes dropped, suggestions trimmed and capped at 6', function () use ($g, $action, $usage) {
    $json = json_encode([
        'narrative' => '<b>Bold</b> &amp; brave',
        'stateUpdates' => [
            'entityChanges' => [['properties' => []], ['entityId' => 'x'], 'junk', ['entityId' => 'y', 'properties' => 'bad'], ['entityId' => ['z']]],
            'inventoryChanges' => ['add:rope', 'steal:gold', 7, 'remove:torch'],
            'statChanges' => 'nope', 'flagChanges' => null,
        ],
        'suggestedActions' => [' a ', '', 5, 'b', 'c', 'd', 'e', 'f', 'g'],
        'profileInsight' => ['not', 'a', 'string'],
        'newPromises' => 'later',
    ]);
    assertEqual(run($g, replied($json, $action, $usage)), applyRead([
        'narrative' => 'Bold & brave',
        'stateUpdates' => ['entityChanges' => [['entityId' => 'x']], 'inventoryChanges' => ['add:rope', 'remove:torch'],
            'statChanges' => [], 'flagChanges' => []],
        'suggestedActions' => ['a', 'b', 'c', 'd', 'e', 'f'],
        'newPromises' => [],
    ], $action, $usage));
});

test('accepted: world-specific fields pass through (Portland)', function () use ($g, $action, $usage) {
    $json = json_encode([
        'narrative' => 'You walk north.',
        'stateUpdates' => ['location' => ['name' => "Powell's"], 'timeAdvance' => 20, 'moneyChange' => -2.5, 'landmarkFound' => 'powells'],
        'currentTime' => '08:00',
        'streetViewLink' => 'https://www.google.com/maps?layer=c&cbll=45.5231,-122.6815',
        'suggestedActions' => ['Enter'],
    ]);
    assertEqual(run($g, replied($json, $action, $usage)), applyRead([
        'narrative' => 'You walk north.',
        'stateUpdates' => ['location' => ['name' => "Powell's"], 'timeAdvance' => 20, 'moneyChange' => -2.5, 'landmarkFound' => 'powells']
            + emptyUpdates(),
        'currentTime' => '08:00',
        'streetViewLink' => 'https://www.google.com/maps?layer=c&cbll=45.5231,-122.6815',
        'suggestedActions' => ['Enter'],
        'newPromises' => [],
    ], $action, $usage));
});

test('rejected (abort): the AI call failed', function () use ($g, $action) {
    $out = run($g, ['gameId' => 'g1', 'action' => $action, 'now' => 'T',
        'ai' => ['ok' => false, 'content' => null, 'usage' => ['input' => 0, 'output' => 0], 'model' => 'm',
            'error' => ['code' => 'AI_NOT_CONFIGURED', 'message' => 'AI API key not configured']]]);
    assertEqual($out, [[
        'type' => 'turn_failed',
        'data' => ['code' => 'AI_NOT_CONFIGURED', 'message' => 'AI API key not configured', 'hint' => 'Try your action again'],
    ]]);
});

exit(report('turn_ai_replied') === 0 ? 0 : 1);
