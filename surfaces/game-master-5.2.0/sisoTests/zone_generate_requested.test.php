<?php
/** ZoneGenerateRequestGate — zone_generate_requested → io_read {state, existing[, parent]}; rejects bad ids. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('zone_generate_requested');

function zread(array $reads, array $carry): array
{
    return [['type' => 'io_read', 'data' => ['reads' => $reads, 'reply' => 'zone_generate_loaded', 'carry' => $carry]]];
}

test('accepted: with parent, direction and constraints; ids cleaned to [A-Za-z0-9_-]', function () use ($g) {
    $out = run($g, ['gameId' => 'g1', 'zoneId' => 'dark cave!', 'parentZoneId' => '../bridge', 'direction' => 'down',
        'constraints' => ['lighting' => 'dim'], 'now' => 'T']);
    assertEqual($out, zread(
        ['state' => ['kind' => 'save', 'gameId' => 'g1'],
         'existing' => ['kind' => 'zone', 'gameId' => 'g1', 'zoneId' => 'dark_cave_'],
         'parent' => ['kind' => 'zone', 'gameId' => 'g1', 'zoneId' => '___bridge']],
        ['gameId' => 'g1', 'zoneId' => 'dark_cave_', 'parentZoneId' => '___bridge', 'direction' => 'down',
         'constraints' => ['lighting' => 'dim'], 'now' => 'T']
    ));
});

test('accepted: no parent — no parent read; bad direction/constraints normalised', function () use ($g) {
    $out = run($g, ['gameId' => 'g1', 'zoneId' => 'cave', 'parentZoneId' => '', 'direction' => 5, 'constraints' => 'x', 'now' => 'T']);
    assertEqual($out, zread(
        ['state' => ['kind' => 'save', 'gameId' => 'g1'], 'existing' => ['kind' => 'zone', 'gameId' => 'g1', 'zoneId' => 'cave']],
        ['gameId' => 'g1', 'zoneId' => 'cave', 'parentZoneId' => null, 'direction' => null, 'constraints' => [], 'now' => 'T']
    ));
});

test('rejected (abort): invalid game id', function () use ($g) {
    assertEqual(run($g, ['gameId' => '..', 'zoneId' => 'cave']), [[
        'type' => 'http_response', 'data' => ['status' => 400, 'body' => ['success' => false, 'error' => 'Invalid game ID']],
    ]]);
});

test('rejected (abort): no zone id', function () use ($g) {
    assertEqual(run($g, ['gameId' => 'g1', 'zoneId' => '']), [[
        'type' => 'http_response',
        'data' => ['status' => 422, 'body' => ['success' => false, 'error' => 'Validation failed', 'errors' => ['zoneId' => 'Zone ID is required']]],
    ]]);
});

test('rejected (abort): zone id over 100 characters, or the reserved "_index"', function () use ($g) {
    $expected = [[
        'type' => 'http_response',
        'data' => ['status' => 422, 'body' => ['success' => false, 'error' => 'Validation failed',
            'errors' => ['zoneId' => 'Zone ID must be at most 100 characters and not "_index"']]],
    ]];
    assertEqual(run($g, ['gameId' => 'g1', 'zoneId' => str_repeat('z', 101)]), $expected);
    assertEqual(run($g, ['gameId' => 'g1', 'zoneId' => '_index']), $expected);
});

exit(report('zone_generate_requested') === 0 ? 0 : 1);
