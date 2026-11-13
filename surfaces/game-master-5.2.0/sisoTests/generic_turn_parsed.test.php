<?php
/**
 * StateUpdateGate — generic_turn_parsed → turn_applied.
 * Includes the Phase 5 inventory-guard cases (formerly tests/Integration/Phase5InventoryGuardTest.php).
 */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('generic_turn_parsed');
$usage = ['input' => 100, 'output' => 50];

/** A game state; inventory and entities vary per case. */
function st(array $inventory, array $entities, int $turn = 3): array
{
    return [
        'gameId' => 'g1', 'worldTemplateId' => 'derelict_spaceship', 'currentZone' => 'bridge', 'turnCount' => $turn,
        'player' => ['name' => 'Ann', 'inventory' => $inventory, 'stats' => ['health' => 100]],
        'entities' => $entities,
        'worldState' => ['day' => 1, 'globalFlags' => []],
        'profile' => ['actionHistory' => [], 'observations' => []],
    ];
}

function parsedTurn(array $updates, string $narrative = 'Something happens.', ?string $insight = null): array
{
    $p = ['narrative' => $narrative,
        'stateUpdates' => $updates + ['entityChanges' => [], 'inventoryChanges' => [], 'statChanges' => [], 'flagChanges' => []],
        'suggestedActions' => ['Look around'], 'newPromises' => []];
    if ($insight !== null) {
        $p['profileInsight'] = $insight;
    }
    return $p;
}

function input(array $state, array $parsed, array $usage): array
{
    return ['gameId' => 'g1', 'action' => 'take the thing',
        'now' => '2026-09-27T10:00:00+00:00', 'parsed' => $parsed, 'usage' => $usage, 'model' => 'claude-sonnet-4-20250514', 'state' => $state];
}

/** Expected output: $after is the state before the turn bookkeeping; the turn adds count, time, history. */
function applied(array $after, array $parsed, array $usage): array
{
    $after['turnCount'] = $after['turnCount'] + 1;
    $after['lastUpdated'] = '2026-09-27T10:00:00+00:00';
    $after['profile']['actionHistory'][] = ['turn' => $after['turnCount'], 'action' => 'take the thing'];
    if (!empty($parsed['profileInsight'])) {
        $after['profile']['observations'][] = $parsed['profileInsight'];
    }
    return [[
        'type' => 'turn_applied',
        'data' => [
            'gameId' => 'g1',
            'state' => $after,
            'response' => ['narrative' => $parsed['narrative'], 'suggestedActions' => ['Look around'],
                'player' => $after['player'], 'turnCount' => $after['turnCount'], 'currentZone' => 'bridge'],
            'usage' => $usage,
            'model' => 'claude-sonnet-4-20250514',
        ],
    ]];
}

$keycard = ['id' => 'keycard', 'name' => 'Keycard', 'present' => true, 'properties' => []];
$chip = ['id' => 'data_chip', 'name' => 'Data Chip', 'present' => false, 'properties' => []];

// ── Phase 5 guard: remove ──────────────────────────

test('guard: remove of an item not held is discarded', function () use ($g, $usage) {
    $s = st(['torch'], []);
    $p = parsedTurn(['inventoryChanges' => ['remove:sword']]);
    assertEqual(run($g, input($s, $p, $usage)), applied($s, $p, $usage));
});

test('guard: remove of a held item succeeds', function () use ($g, $usage) {
    $s = st(['torch', 'rope'], []);
    $p = parsedTurn(['inventoryChanges' => ['remove:torch']]);
    $after = $s; $after['player']['inventory'] = ['rope'];
    assertEqual(run($g, input($s, $p, $usage)), applied($after, $p, $usage));
});

// ── Phase 5 guard: add ─────────────────────────────

test('guard: add of an item not in the scene is discarded (hallucinated item)', function () use ($g, $usage) {
    $s = st([], []);
    $p = parsedTurn(['inventoryChanges' => ['add:sword']]);
    assertEqual(run($g, input($s, $p, $usage)), applied($s, $p, $usage));
});

test('guard: add matched by entity id succeeds', function () use ($g, $usage, $keycard) {
    $s = st([], [$keycard]);
    $p = parsedTurn(['inventoryChanges' => ['add:keycard']]);
    $after = $s; $after['player']['inventory'] = ['keycard'];
    assertEqual(run($g, input($s, $p, $usage)), applied($after, $p, $usage));
});

test('guard: add matched by entity name, case-insensitive', function () use ($g, $usage, $keycard) {
    $s = st([], [$keycard]);
    $p = parsedTurn(['inventoryChanges' => ['add:KEYCARD']]);
    $after = $s; $after['player']['inventory'] = ['KEYCARD'];
    assertEqual(run($g, input($s, $p, $usage)), applied($after, $p, $usage));
});

test('guard: an entity with present=false is still known, so the add is permitted', function () use ($g, $usage, $chip) {
    $s = st([], [$chip]);
    $p = parsedTurn(['inventoryChanges' => ['add:data chip']]);
    $after = $s; $after['player']['inventory'] = ['data chip'];
    assertEqual(run($g, input($s, $p, $usage)), applied($after, $p, $usage));
});

test('guard: add of the id of an entity changed this turn succeeds', function () use ($g, $usage) {
    $s = st([], []);
    $p = parsedTurn(['entityChanges' => [['entityId' => 'medkit', 'properties' => ['given' => true]]], 'inventoryChanges' => ['add:medkit']]);
    $after = $s; $after['player']['inventory'] = ['medkit'];
    assertEqual(run($g, input($s, $p, $usage)), applied($after, $p, $usage));
});

test('guard: no duplicate addition', function () use ($g, $usage, $keycard) {
    $s = st(['keycard'], [$keycard]);
    $p = parsedTurn(['inventoryChanges' => ['add:keycard']]);
    assertEqual(run($g, input($s, $p, $usage)), applied($s, $p, $usage));
});

test('guard: mixed changes — valid ones apply, invalid ones are discarded', function () use ($g, $usage) {
    $badge = ['id' => 'new_badge', 'name' => 'New Badge', 'present' => true, 'properties' => []];
    $s = st(['old_badge', 'torch'], [$badge]);
    $p = parsedTurn(['inventoryChanges' => ['remove:old_badge', 'add:new_badge', 'add:laser_gun', 'remove:ghost_item']]);
    $after = $s; $after['player']['inventory'] = ['torch', 'new_badge'];
    assertEqual(run($g, input($s, $p, $usage)), applied($after, $p, $usage));
});

// ── Entities, stats, flags, profile ─────────────────

test('entity changes apply only to known entities', function () use ($g, $usage) {
    $terminal = ['id' => 'terminal', 'name' => 'Terminal', 'present' => true, 'properties' => ['powered' => false]];
    $s = st([], [$terminal]);
    $p = parsedTurn(['entityChanges' => [['entityId' => 'terminal', 'properties' => ['powered' => true]], ['entityId' => 'phantom', 'properties' => ['x' => 1]]]]);
    $after = $s; $after['entities'][0]['properties']['powered'] = true;
    assertEqual(run($g, input($s, $p, $usage)), applied($after, $p, $usage));
});

test('stats are set on the player and mirrored into worldState; flags set', function () use ($g, $usage) {
    $s = st([], []);
    $p = parsedTurn(['statChanges' => ['health' => 80, 'day' => 2], 'flagChanges' => ['alarm' => true]]);
    $after = $s;
    $after['player']['stats'] = ['health' => 80, 'day' => 2];
    $after['worldState']['health'] = 80;
    $after['worldState']['day'] = 2;
    $after['worldState']['globalFlags'] = ['alarm' => true];
    assertEqual(run($g, input($s, $p, $usage)), applied($after, $p, $usage));
});

test('profile insight is recorded; turn count increments from zero', function () use ($g, $usage) {
    $s = st([], [], 0);
    $p = parsedTurn([], 'You pause.', 'Player is cautious');
    assertEqual(run($g, input($s, $p, $usage)), applied($s, $p, $usage));
});

test('a bare state is filled in with the default sections', function () use ($g, $usage) {
    $p = parsedTurn([]);
    $out = run($g, ['gameId' => 'g1', 'action' => 'wait', 'now' => 'T', 'parsed' => $p,
        'usage' => $usage, 'model' => 'm', 'state' => []]);
    assertEqual($out, [[
        'type' => 'turn_applied',
        'data' => [
            'gameId' => 'g1',
            'state' => [
                'player' => ['inventory' => [], 'stats' => []],
                'worldState' => ['day' => 1, 'globalFlags' => []],
                'profile' => ['actionHistory' => [['turn' => 1, 'action' => 'wait']], 'observations' => []],
                'entities' => [],
                'turnCount' => 1,
                'lastUpdated' => 'T',
            ],
            'response' => ['narrative' => 'Something happens.', 'suggestedActions' => ['Look around'],
                'player' => ['inventory' => [], 'stats' => []], 'turnCount' => 1, 'currentZone' => ''],
            'usage' => $usage,
            'model' => 'm',
        ],
    ]]);
});

exit(report('generic_turn_parsed') === 0 ? 0 : 1);
