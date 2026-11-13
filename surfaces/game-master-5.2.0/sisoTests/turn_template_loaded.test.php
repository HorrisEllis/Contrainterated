<?php
/**
 * ContextBuildGate — turn_template_loaded → ai_call with the turn prompts.
 * Expected prompts come from the pre-port gate (sisoTests/fixtures/README.md).
 */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, assertContains, report};

$g = gate('turn_template_loaded');
$fx = __DIR__ . '/fixtures';
$state = json_decode(file_get_contents("$fx/generic_state.json"), true);
$template = json_decode(file_get_contents(__DIR__ . '/../data/worlds/derelict_spaceship.json'), true);
$action = 'examine the terminal';

function aiCall(string $system, string $user, array $carry): array
{
    return [[
        'type' => 'ai_call',
        'data' => ['system' => $system, 'user' => $user, 'reply' => 'turn_ai_replied', 'carry' => $carry],
    ]];
}

test('accepted: prompts match the pre-port gate for the same state and template', function () use ($g, $fx, $state, $template, $action) {
    $out = run($g, ['gameId' => 'g1', 'action' => $action, 'now' => 'T', 'state' => $state, 'reads' => reads(['template' => $template])]);
    assertEqual($out, aiCall(
        file_get_contents("$fx/generic_system.txt"),
        file_get_contents("$fx/generic_user.txt"),
        ['gameId' => 'g1', 'action' => $action, 'now' => 'T']
    ));
});

test('accepted: unreadable template → default theme/tone/rules; empty state → "none" constraint lists', function () use ($g, $action) {
    $out = run($g, ['gameId' => 'g1', 'action' => $action, 'now' => 'T', 'state' => [], 'reads' => reads(['template' => 'missing'])]);
    assertEqual(array_map(fn($e) => [$e['type'], $e['data']['reply'], $e['data']['carry']], $out),
        [['ai_call', 'turn_ai_replied', ['gameId' => 'g1', 'action' => $action, 'now' => 'T']]]);
    $system = $out[0]['data']['system'];
    assertContains("THEME: adventure\nTONE: mysterious", $system);
    assertContains("WORLD RULES:\nStandard adventure rules apply.", $system);
    assertContains('Current contents: [none]', $system);
    assertContains('Current entity IDs: [none]', $system);
    assertContains("ENTITIES IN ZONE:\nNone visible", $system);
    assertEqual($out[0]['data']['user'], 'PLAYER ACTION: examine the terminal');
});

test('accepted: saved entities without a name or id still render', function () use ($g, $action) {
    $state = ['entities' => [['id' => 'door'], ['name' => 'Ghost'], []]];
    $out = run($g, ['gameId' => 'g1', 'action' => $action, 'now' => 'T', 'state' => $state, 'reads' => reads(['template' => 'missing'])]);
    assertContains("ENTITIES IN ZONE:\n- door (id: door): []\n- Ghost (id: ?): []\n- Unnamed (id: ?): []", $out[0]['data']['system']);
});

exit(report('turn_template_loaded') === 0 ? 0 : 1);
