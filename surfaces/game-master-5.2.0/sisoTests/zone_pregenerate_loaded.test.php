<?php
/** ZonePreGenerationGate — zone_pregenerate_loaded → [zone_target × N, io_collect]; rejects unknown game/zone (404). */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('zone_pregenerate_loaded');          // factory default: max 3
$g2 = gate('zone_pregenerate_loaded', ['maxZonePreGenerate' => 2]);

$state = ['currentZone' => 'clearing', 'worldTemplate' => ['theme' => 'fantasy'], 'worldState' => [], 'player' => [], 'profile' => []];
$world = ['worldTemplate' => ['theme' => 'fantasy'], 'currentZone' => 'clearing', 'worldState' => [], 'playerStats' => [], 'observations' => []];
$from = ['name' => 'Clearing', 'description' => 'Sunlit.', 'exits' => [
    ['direction' => 'north', 'targetZoneId' => 'cave'],
    ['direction' => 'east', 'targetZoneId' => null],
    ['direction' => 'south', 'targetZoneId' => 'entrance'],
    ['direction' => 'west', 'targetZoneId' => 'old mill'],
    ['direction' => 'up', 'targetZoneId' => 'cave'],
    ['direction' => 'down', 'targetZoneId' => 'crypt'],
    ['direction' => 'in', 'targetZoneId' => '_index'],
]];

function target(string $zoneId, string $dir, array $world): array
{
    return ['type' => 'zone_target', 'data' => ['gameId' => 'g1', 'zoneId' => $zoneId, 'parentZoneId' => 'clearing', 'direction' => $dir,
        'constraints' => [], 'world' => $world, 'parent' => ['name' => 'Clearing', 'description' => 'Sunlit.'], 'now' => 'T']];
}

function collectFor(array $ids): array
{
    $reads = ['state' => ['kind' => 'save', 'gameId' => 'g1'], 'index' => ['kind' => 'zone_index', 'gameId' => 'g1']];
    foreach ($ids as $id) {
        $reads["existing:{$id}"] = ['kind' => 'zone', 'gameId' => 'g1', 'zoneId' => $id];
    }
    return ['type' => 'io_collect', 'data' => [
        'types' => ['zone_parsed', 'zone_failed'],
        'where' => ['gameId' => 'g1'],
        'key' => 'zoneId',
        'expect' => $ids,
        'reads' => $reads,
        'reply' => 'zones_settled',
        'carry' => ['gameId' => 'g1', 'respondWith' => 'zone_pregenerate_stored', 'fromZoneId' => 'clearing', 'requested' => $ids],
    ]];
}

$carry = ['gameId' => 'g1', 'fromZoneId' => 'clearing', 'now' => 'T'];

test('accepted: targets that cannot be zone ids ("_index", over 100 characters) are skipped', function () use ($g, $state, $world, $carry) {
    $from = ['name' => 'Clearing', 'description' => 'Sunlit.', 'exits' => [
        ['direction' => 'in', 'targetZoneId' => '_index'],
        ['direction' => 'out', 'targetZoneId' => str_repeat('x', 101)],
        ['direction' => 'north', 'targetZoneId' => 'cave'],
    ]];
    $out = run($g, $carry + ['reads' => reads(['state' => $state, 'from' => $from, 'index' => 'missing'])]);
    assertEqual($out, [target('cave', 'north', $world), collectFor(['cave'])]);
});

test('accepted: open exits skipped, generated zones skipped, duplicates once, ids cleaned, capped at 3', function () use ($g, $state, $from, $world, $carry) {
    $out = run($g, $carry + ['reads' => reads(['state' => $state, 'from' => $from, 'index' => ['entrance' => ['name' => 'Entrance']]])]);
    assertEqual($out, [target('cave', 'north', $world), target('old_mill', 'west', $world), target('crypt', 'down', $world),
        collectFor(['cave', 'old_mill', 'crypt'])]);
});

test('accepted: configured limit of 2', function () use ($g2, $state, $from, $world, $carry) {
    $out = run($g2, $carry + ['reads' => reads(['state' => $state, 'from' => $from, 'index' => 'missing'])]);
    assertEqual($out, [target('cave', 'north', $world), target('entrance', 'south', $world), collectFor(['cave', 'entrance'])]);
});

test('accepted (empty fan-out): from-zone taken from the save when its file is missing; nothing left to generate', function () use ($g, $state, $world, $carry) {
    $s = $state + ['zones' => ['clearing' => ['name' => 'Clearing', 'description' => 'Sunlit.', 'exits' => [['direction' => 'east', 'targetZoneId' => null]]]]];
    $out = run($g, $carry + ['reads' => reads(['state' => $s, 'from' => 'missing', 'index' => 'missing'])]);
    assertEqual($out, [collectFor([])]);
});

test('rejected (abort): no such game', function () use ($g, $carry) {
    assertEqual(run($g, $carry + ['reads' => reads(['state' => 'missing', 'from' => 'missing', 'index' => 'missing'])]), [[
        'type' => 'http_response', 'data' => ['status' => 404, 'body' => ['success' => false, 'error' => "Game 'g1' not found"]],
    ]]);
});

test('rejected (abort): from-zone unknown', function () use ($g, $state, $carry) {
    assertEqual(run($g, $carry + ['reads' => reads(['state' => $state, 'from' => 'missing', 'index' => 'missing'])]), [[
        'type' => 'http_response', 'data' => ['status' => 404, 'body' => ['success' => false, 'error' => "Zone 'clearing' not found"]],
    ]]);
});

exit(report('zone_pregenerate_loaded') === 0 ? 0 : 1);
