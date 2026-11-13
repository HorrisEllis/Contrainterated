<?php
/** ZoneGenerateCheckGate — zone_generate_loaded → [zone_target, io_collect]; rejects unknown game (404) and existing zone (409). */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('zone_generate_loaded');

$state = [
    'currentZone' => 'bridge',
    'worldTemplate' => ['theme' => 'sci-fi', 'tone' => 'eerie', 'worldRules' => ['Air is scarce']],
    'worldState' => ['day' => 2],
    'player' => ['name' => 'Ann', 'stats' => ['health' => 90]],
    'profile' => ['observations' => ['careful']],
    'entities' => [['id' => 'not-needed-by-the-zone-prompt']],
];
$world = ['worldTemplate' => $state['worldTemplate'], 'currentZone' => 'bridge', 'worldState' => ['day' => 2],
    'playerStats' => ['health' => 90], 'observations' => ['careful']];
$carry = ['gameId' => 'g1', 'zoneId' => 'engine', 'parentZoneId' => 'bridge', 'direction' => 'down', 'constraints' => ['lighting' => 'dim'], 'now' => 'T'];

function planned(array $world, ?array $parent, ?string $parentId): array
{
    return [
        ['type' => 'zone_target', 'data' => ['gameId' => 'g1', 'zoneId' => 'engine', 'parentZoneId' => $parentId, 'direction' => 'down',
            'constraints' => ['lighting' => 'dim'], 'world' => $world, 'parent' => $parent, 'now' => 'T']],
        ['type' => 'io_collect', 'data' => [
            'types' => ['zone_parsed', 'zone_failed'],
            'where' => ['gameId' => 'g1'],
            'key' => 'zoneId',
            'expect' => ['engine'],
            'reads' => ['state' => ['kind' => 'save', 'gameId' => 'g1'], 'index' => ['kind' => 'zone_index', 'gameId' => 'g1'],
                'existing:engine' => ['kind' => 'zone', 'gameId' => 'g1', 'zoneId' => 'engine']],
            'reply' => 'zones_settled',
            'carry' => ['gameId' => 'g1', 'respondWith' => 'zone_generate_stored', 'fromZoneId' => null, 'requested' => ['engine']],
        ]],
    ];
}

test('accepted: parent zone found — its name and description go to the prompt', function () use ($g, $state, $world, $carry) {
    $out = run($g, $carry + ['reads' => reads(['state' => $state, 'existing' => 'missing',
        'parent' => ['name' => 'Bridge', 'description' => 'Dark.', 'exits' => []]])]);
    assertEqual($out, planned($world, ['name' => 'Bridge', 'description' => 'Dark.'], 'bridge'));
});

test('accepted: parent zone not stored — no parent summary', function () use ($g, $state, $world, $carry) {
    $out = run($g, $carry + ['reads' => reads(['state' => $state, 'existing' => 'missing', 'parent' => 'missing'])]);
    assertEqual($out, planned($world, null, 'bridge'));
});

test('rejected (abort): no such game', function () use ($g, $carry) {
    assertEqual(run($g, $carry + ['reads' => reads(['state' => 'missing', 'existing' => 'missing'])]), [[
        'type' => 'http_response', 'data' => ['status' => 404, 'body' => ['success' => false, 'error' => "Game 'g1' not found"]],
    ]]);
});

test('rejected (abort): zone already exists (even if unreadable)', function () use ($g, $state, $carry) {
    assertEqual(run($g, $carry + ['reads' => reads(['state' => $state, 'existing' => 'corrupt'])]), [[
        'type' => 'http_response', 'data' => ['status' => 409, 'body' => ['success' => false, 'error' => "Zone 'engine' already exists"]],
    ]]);
});

exit(report('zone_generate_loaded') === 0 ? 0 : 1);
