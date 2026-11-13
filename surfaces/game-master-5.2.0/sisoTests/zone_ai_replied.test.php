<?php
/** ZoneParseGate — zone_ai_replied → zone_parsed; rejects a failed AI call (zone_failed). */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('zone_ai_replied');

function zin(string $content, ?string $parent = 'bridge', ?string $dir = 'north', string $zoneId = 'dark_cave'): array
{
    return ['gameId' => 'g1', 'zoneId' => $zoneId, 'parentZoneId' => $parent, 'direction' => $dir, 'now' => 'T',
        'ai' => ['ok' => true, 'content' => $content, 'usage' => ['input' => 300, 'output' => 200], 'model' => 'm', 'error' => null]];
}

function parsedZone(array $fields, ?string $parent = 'bridge', ?string $dir = 'north', string $zoneId = 'dark_cave'): array
{
    return [[
        'type' => 'zone_parsed',
        'data' => ['gameId' => 'g1', 'zoneId' => $zoneId, 'zone' => ['id' => $zoneId] + $fields + [
            'parentZoneId' => $parent, 'direction' => $dir, 'generated' => 'T', 'tokensUsed' => 500]],
    ]];
}

test('accepted: well-formed zone, bounded and normalised', function () use ($g) {
    $json = json_encode([
        'name' => 'Dark Cave', 'description' => 'Water drips.',
        'entities' => [['id' => 'bat colony!', 'name' => 'Bats', 'properties' => ['type' => 'npc']], 'junk', ['name' => 'Rock']],
        'exits' => [['direction' => 'SOUTH', 'targetZoneId' => 'bridge', 'locked' => 1], ['description' => 'A crack.']],
        'properties' => ['lighting' => 'pitch', 'danger_level' => 14, 'ambient_sounds' => ['drip', 3], 'extra' => true],
    ]);
    assertEqual(run($g, zin("Here:\n```json\n{$json}\n```")), parsedZone([
        'name' => 'Dark Cave',
        'description' => 'Water drips.',
        'entities' => [
            ['id' => 'bat_colony_', 'name' => 'Bats', 'description' => 'An object.', 'properties' => ['type' => 'npc'], 'present' => true],
            ['id' => 'dark_cave_entity_2', 'name' => 'Rock', 'description' => 'An object.', 'properties' => [], 'present' => true],
        ],
        'exits' => [
            ['direction' => 'south', 'targetZoneId' => 'bridge', 'description' => 'An exit.', 'locked' => true, 'requirements' => []],
            ['direction' => 'unknown', 'targetZoneId' => null, 'description' => 'A crack.', 'locked' => false, 'requirements' => []],
        ],
        'properties' => ['lighting' => 'normal', 'danger_level' => 10, 'ambient_sounds' => ['drip'], 'special_features' => []],
    ]));
});

test('accepted: unparseable reply → fallback zone named from direction and id', function () use ($g) {
    assertEqual(run($g, zin('The AI rambles without JSON.')), parsedZone([
        'name' => 'Northern Cave',
        'description' => 'You find yourself in Northern Cave. The area is shrouded in mystery, waiting to be explored.',
        'entities' => [['id' => 'dark_cave_feature_1', 'name' => 'Strange Formation', 'description' => 'An unusual feature catches your attention.',
            'properties' => ['interactable' => true, 'type' => 'object'], 'present' => true]],
        'exits' => [['direction' => 'south', 'targetZoneId' => 'bridge', 'description' => 'The way you came.', 'locked' => false, 'requirements' => []]],
        'properties' => ['lighting' => 'dim', 'danger_level' => 1, 'ambient_sounds' => [], 'special_features' => []],
    ]));
});

test('accepted: fallback with no direction and a one-word id', function () use ($g) {
    assertEqual(run($g, zin('{"narrative": "not a zone"}', null, null, 'attic')), parsedZone([
        'name' => 'Unknown Area',
        'description' => 'You find yourself in Unknown Area. The area is shrouded in mystery, waiting to be explored.',
        'entities' => [['id' => 'attic_feature_1', 'name' => 'Strange Formation', 'description' => 'An unusual feature catches your attention.',
            'properties' => ['interactable' => true, 'type' => 'object'], 'present' => true]],
        'exits' => [['direction' => 'back', 'targetZoneId' => null, 'description' => 'The way you came.', 'locked' => false, 'requirements' => []]],
        'properties' => ['lighting' => 'dim', 'danger_level' => 1, 'ambient_sounds' => [], 'special_features' => []],
    ], null, null, 'attic'));
});

test('rejected (abort): the AI call failed', function () use ($g) {
    $in = zin('');
    $in['ai'] = ['ok' => false, 'content' => null, 'usage' => ['input' => 0, 'output' => 0], 'model' => 'm',
        'error' => ['code' => 'AI_REQUEST_FAILED', 'message' => 'Rate limited (429)']];
    assertEqual(run($g, $in), [[
        'type' => 'zone_failed', 'data' => ['gameId' => 'g1', 'zoneId' => 'dark_cave', 'error' => 'Rate limited (429)'],
    ]]);
});

exit(report('zone_ai_replied') === 0 ? 0 : 1);
