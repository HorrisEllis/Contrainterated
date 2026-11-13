<?php
/** ZoneStoreGate — zones_settled → io_write {zone:<id>…, index, state} → respondWith. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('zones_settled');

function zone(string $id, ?string $parent, ?string $dir, array $exits): array
{
    return ['id' => $id, 'name' => ucfirst($id), 'description' => "The {$id}.", 'entities' => [], 'exits' => $exits,
        'properties' => ['lighting' => 'dim', 'danger_level' => 2, 'ambient_sounds' => [], 'special_features' => []],
        'parentZoneId' => $parent, 'direction' => $dir, 'generated' => 'T', 'tokensUsed' => 10];
}

$cave = zone('cave', 'clearing', 'north', [['direction' => 'south', 'targetZoneId' => 'clearing', 'description' => 'd', 'locked' => false, 'requirements' => []]]);
$mill = zone('mill', 'clearing', 'west', []);
$state = ['gameId' => 'g1', 'zones' => ['clearing' => ['name' => 'Clearing', 'exits' => [
    ['direction' => 'north', 'targetZoneId' => null, 'locked' => false],
    ['direction' => 'west', 'targetZoneId' => 'mill', 'locked' => false],
]]]];

$collected = [
    ['type' => 'zone_parsed', 'data' => ['gameId' => 'g1', 'zoneId' => 'cave', 'zone' => $cave]],
    ['type' => 'zone_failed', 'data' => ['gameId' => 'g1', 'zoneId' => 'crypt', 'error' => 'Rate limited (429)']],
    ['type' => 'zone_parsed', 'data' => ['gameId' => 'g1', 'zoneId' => 'mill', 'zone' => $mill]],
];
$carryIn = ['gameId' => 'g1', 'respondWith' => 'zone_pregenerate_stored', 'fromZoneId' => 'clearing', 'requested' => ['cave', 'crypt', 'mill']];

test('accepted: two zones written in one batch; index and state updated together', function () use ($g, $cave, $mill, $state, $collected, $carryIn) {
    $out = run($g, $carryIn + ['collected' => $collected, 'reads' => reads(['state' => $state, 'index' => ['old' => ['name' => 'Old']]])]);

    $newState = $state;
    $newState['zones']['clearing']['exits'][0]['targetZoneId'] = 'cave';   // open north exit now leads to the cave
    $newState['zones']['cave'] = ['name' => 'Cave', 'description' => 'The cave.',
        'exits' => [['direction' => 'south', 'targetZoneId' => 'clearing', 'locked' => false]], 'visited' => false, 'generated' => 'T'];
    $newState['zones']['mill'] = ['name' => 'Mill', 'description' => 'The mill.', 'exits' => [], 'visited' => false, 'generated' => 'T'];

    assertEqual($out, [[
        'type' => 'io_write',
        'data' => [
            'writes' => [
                'zone:cave' => ['kind' => 'zone', 'gameId' => 'g1', 'zoneId' => 'cave', 'op' => 'put', 'content' => $cave],
                'zone:mill' => ['kind' => 'zone', 'gameId' => 'g1', 'zoneId' => 'mill', 'op' => 'put', 'content' => $mill],
                'index' => ['kind' => 'zone_index', 'gameId' => 'g1', 'op' => 'put', 'content' => [
                    'old' => ['name' => 'Old'],
                    'cave' => ['name' => 'Cave', 'parentZoneId' => 'clearing', 'direction' => 'north', 'exits' => ['south'], 'generated' => 'T', 'dangerLevel' => 2],
                    'mill' => ['name' => 'Mill', 'parentZoneId' => 'clearing', 'direction' => 'west', 'exits' => [], 'generated' => 'T', 'dangerLevel' => 2],
                ]],
                'state' => ['kind' => 'save', 'gameId' => 'g1', 'op' => 'put', 'content' => $newState],
            ],
            'reply' => 'zone_pregenerate_stored',
            'carry' => ['gameId' => 'g1', 'fromZoneId' => 'clearing', 'requested' => ['cave', 'crypt', 'mill'],
                'zones' => [$cave, $mill], 'failed' => [['zoneId' => 'crypt', 'error' => 'Rate limited (429)']]],
        ],
    ]]);
});

test('accepted: a zone whose file already exists is skipped and reported, the rest stored', function () use ($g, $cave, $mill, $state, $collected, $carryIn) {
    $out = run($g, $carryIn + ['collected' => $collected,
        'reads' => reads(['state' => $state, 'index' => 'missing', 'existing:cave' => ['name' => 'Hand-made cave'], 'existing:mill' => 'missing'])]);
    $newState = $state;
    $newState['zones']['mill'] = ['name' => 'Mill', 'description' => 'The mill.', 'exits' => [], 'visited' => false, 'generated' => 'T'];
    assertEqual($out, [[
        'type' => 'io_write',
        'data' => [
            'writes' => [
                'zone:mill' => ['kind' => 'zone', 'gameId' => 'g1', 'zoneId' => 'mill', 'op' => 'put', 'content' => $mill],
                'index' => ['kind' => 'zone_index', 'gameId' => 'g1', 'op' => 'put', 'content' => [
                    'mill' => ['name' => 'Mill', 'parentZoneId' => 'clearing', 'direction' => 'west', 'exits' => [], 'generated' => 'T', 'dangerLevel' => 2],
                ]],
                'state' => ['kind' => 'save', 'gameId' => 'g1', 'op' => 'put', 'content' => $newState],
            ],
            'reply' => 'zone_pregenerate_stored',
            'carry' => ['gameId' => 'g1', 'fromZoneId' => 'clearing', 'requested' => ['cave', 'crypt', 'mill'], 'zones' => [$mill],
                'failed' => [['zoneId' => 'crypt', 'error' => 'Rate limited (429)'], ['zoneId' => 'cave', 'error' => "Zone 'cave' already exists"]]],
        ],
    ]]);
});

test('rejected (abort): the save is unreadable — nothing stored, every zone reported', function () use ($g, $mill, $carryIn) {
    $out = run($g, ['respondWith' => 'zone_generate_stored', 'requested' => ['mill']] + $carryIn + [
        'collected' => [['type' => 'zone_parsed', 'data' => ['gameId' => 'g1', 'zoneId' => 'mill', 'zone' => $mill]]],
        'reads' => reads(['state' => 'missing', 'index' => 'missing'])]);
    assertEqual($out, [[
        'type' => 'io_write',
        'data' => ['writes' => [], 'reply' => 'zone_generate_stored',
            'carry' => ['gameId' => 'g1', 'fromZoneId' => 'clearing', 'requested' => ['mill'], 'zones' => [],
                'failed' => [['zoneId' => 'mill', 'error' => "Game 'g1' not found"]]]],
    ]]);
});

test('accepted (empty fan-out): nothing generated — empty write batch; other games\' results ignored', function () use ($g, $cave, $carryIn) {
    $out = run($g, $carryIn + ['collected' => [['type' => 'zone_parsed', 'data' => ['gameId' => 'other', 'zoneId' => 'cave', 'zone' => $cave]]],
        'reads' => reads(['state' => ['gameId' => 'g1'], 'index' => 'missing'])]);
    assertEqual($out, [[
        'type' => 'io_write',
        'data' => ['writes' => [], 'reply' => 'zone_pregenerate_stored',
            'carry' => ['gameId' => 'g1', 'fromZoneId' => 'clearing', 'requested' => ['cave', 'crypt', 'mill'], 'zones' => [], 'failed' => []]],
    ]]);
});

exit(report('zones_settled') === 0 ? 0 : 1);
