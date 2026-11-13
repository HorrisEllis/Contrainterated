<?php
/**
 * ZoneContextGate — zone_target → ai_call with the zone prompts.
 * Expected prompts come from the pre-port gate (sisoTests/fixtures/README.md).
 */

require_once __DIR__ . '/helper.php';

use GameMaster\Pure\Zones;
use function SisoTest\{test, assertEqual, assertContains, report};

$g = gate('zone_target');
$fx = __DIR__ . '/fixtures';
$state = json_decode(file_get_contents("$fx/generic_state.json"), true);

test('accepted: prompts match the pre-port gate for the same world, parent and constraints', function () use ($g, $fx, $state) {
    $out = run($g, [
        'gameId' => 'g1', 'zoneId' => 'engine_room', 'parentZoneId' => 'bridge', 'direction' => 'down',
        'constraints' => ['lighting' => 'dim', 'hazards' => ['steam', 'sparks']],
        'world' => Zones::worldContext($state),
        'parent' => ['name' => 'Command Bridge', 'description' => 'Consoles flicker in the dark.'],
        'now' => 'T',
    ]);
    assertEqual($out, [[
        'type' => 'ai_call',
        'data' => [
            'system' => file_get_contents("$fx/zone_system.txt"),
            'user' => file_get_contents("$fx/zone_user.txt"),
            'reply' => 'zone_ai_replied',
            'carry' => ['gameId' => 'g1', 'zoneId' => 'engine_room', 'parentZoneId' => 'bridge', 'direction' => 'down', 'now' => 'T'],
        ],
    ]]);
});

test('accepted: bare world, no parent — defaults and a minimal user prompt', function () use ($g) {
    $out = run($g, ['gameId' => 'g1', 'zoneId' => 'cave', 'parentZoneId' => null, 'direction' => null, 'constraints' => [],
        'world' => Zones::worldContext([]), 'parent' => null, 'now' => 'T']);
    assertEqual(array_map(fn($e) => [$e['type'], $e['data']['reply'], $e['data']['carry']], $out),
        [['ai_call', 'zone_ai_replied', ['gameId' => 'g1', 'zoneId' => 'cave', 'parentZoneId' => null, 'direction' => null, 'now' => 'T']]]);
    assertContains("Theme: fantasy\nTone: mysterious\n", $out[0]['data']['system']);
    assertEqual($out[0]['data']['user'],
        "Generate zone with ID: cave\n\nGenerate a zone that fits naturally in this world and provides interesting exploration opportunities.");
});

test('accepted: nested constraint values are written as JSON, not "Array"', function () use ($g) {
    $out = run($g, ['gameId' => 'g1', 'zoneId' => 'cave', 'parentZoneId' => null, 'direction' => null,
        'constraints' => ['loot' => [['gold' => 5], 'rope'], 'mood' => ['calm' => true], 'lit' => false],
        'world' => Zones::worldContext([]), 'parent' => null, 'now' => 'T']);
    assertContains("Constraints:\n- loot: {\"gold\":5}, rope\n- mood: 1\n- lit: ", $out[0]['data']['user']);
});

exit(report('zone_target') === 0 ? 0 : 1);
