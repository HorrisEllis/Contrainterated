<?php
/**
 * PortlandContextGate — portland_turn_loaded → ai_call with the Portland prompts.
 * Expected prompts come from the pre-port gate (sisoTests/fixtures/README.md).
 */

require_once __DIR__ . '/helper.php';

use GameMaster\Gates\PortlandContextGate;
use function SisoTest\{test, assertEqual, assertTrue, assertContains, report};

$g = gate('portland_turn_loaded');
$fx = __DIR__ . '/fixtures';
$state = json_decode(file_get_contents("$fx/portland_state.json"), true);
$template = json_decode(file_get_contents(__DIR__ . '/../data/worlds/portland_scavenger.json'), true);
$action = 'walk to powells';

test('accepted: prompts match the pre-port gate for the same state and template', function () use ($g, $fx, $state, $template, $action) {
    $out = run($g, ['gameId' => 'p1', 'action' => $action, 'now' => 'T', 'state' => $state, 'reads' => reads(['template' => $template])]);
    assertEqual($out, [[
        'type' => 'ai_call',
        'data' => [
            'system' => file_get_contents("$fx/portland_system.txt"),
            'user' => file_get_contents("$fx/portland_user.txt"),
            'reply' => 'turn_ai_replied',
            'carry' => ['gameId' => 'p1', 'action' => $action, 'now' => 'T'],
        ],
    ]]);
});

test('accepted: unreadable template still yields a prompt (no landmarks, default location)', function () use ($g, $action) {
    $out = run($g, ['gameId' => 'p1', 'action' => $action, 'now' => 'T', 'state' => [], 'reads' => reads(['template' => 'missing'])]);
    assertEqual(array_map(fn($e) => [$e['type'], $e['data']['reply'], $e['data']['carry']], $out),
        [['ai_call', 'turn_ai_replied', ['gameId' => 'p1', 'action' => $action, 'now' => 'T']]]);
    assertContains('- Location: Pioneer Courthouse Square', $out[0]['data']['system']);
    assertContains('All landmarks found!', $out[0]['data']['system']);
    assertContains('No note found.', $out[0]['data']['system']);
});

test('distance helper: straight-line miles between known Portland points', function () {
    // Pioneer Courthouse Square → Powell's City of Books is ~0.3 mi;
    // → Pittock Mansion is ~1.8 mi in a straight line (both from the original test suite).
    $powells = PortlandContextGate::distanceMiles(45.5189, -122.6793, 45.5231, -122.6815);
    $pittock = PortlandContextGate::distanceMiles(45.5189, -122.6793, 45.5246, -122.7162);
    assertTrue($powells > 0.2 && $powells < 0.5, "Powell's ~0.3 mi, got {$powells}");
    assertTrue($pittock > 1.5 && $pittock < 2.5, "Pittock ~1.8 mi, got {$pittock}");
});

exit(report('portland_turn_loaded') === 0 ? 0 : 1);
