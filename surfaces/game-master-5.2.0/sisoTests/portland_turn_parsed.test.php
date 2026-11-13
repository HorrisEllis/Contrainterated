<?php
/**
 * PortlandStateGate — portland_turn_parsed → turn_applied.
 * Cases from the original PortlandGatesTest (time, landmark, money, win, Street View),
 * which could never pass before: the old gate read a field the event did not have.
 */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('portland_turn_parsed');
$usage = ['input' => 10, 'output' => 20];
$pioneer = ['name' => 'Pioneer Courthouse Square', 'lat' => 45.5189, 'lng' => -122.6793,
    'streetview' => 'https://www.google.com/maps?layer=c&cbll=45.5189,-122.6793'];

function pstate(array $visited = [], float|int $money = 0): array
{
    return [
        'gameId' => 'p1', 'worldTemplateId' => 'portland_scavenger', 'currentZone' => 'pioneer_square', 'turnCount' => 0,
        'player' => ['name' => 'Sam', 'inventory' => ['crumpled_note'], 'stats' => ['money' => $money, 'landmarks_found' => count($visited)]],
        'worldState' => ['time' => '06:00', 'date' => 'October 15', 'landmarks_visited' => $visited, 'globalFlags' => []],
        'profile' => ['actionHistory' => [], 'observations' => []],
    ];
}

function pparsed(array $stateUpdates, array $extra = []): array
{
    return ['narrative' => 'N.', 'stateUpdates' => $stateUpdates + ['entityChanges' => [], 'inventoryChanges' => [], 'statChanges' => [], 'flagChanges' => []],
        'suggestedActions' => ['Go'], 'newPromises' => []] + $extra;
}

/** The real template supplies the landmark ids a claim is checked against. */
function ptemplate(): array
{
    static $t = null;
    return $t ??= json_decode(file_get_contents(__DIR__ . '/../data/worlds/portland_scavenger.json'), true);
}

function prun($g, array $state, array $parsed, array $usage, string|array $template = 'use-real'): array
{
    return run($g, ['gameId' => 'p1', 'action' => 'walk north', 'now' => 'NOW',
        'parsed' => $parsed, 'usage' => $usage, 'model' => 'claude-sonnet-4-20250514', 'state' => $state,
        'reads' => reads(['template' => $template === 'use-real' ? ptemplate() : $template])]);
}

/** Expected turn_applied: $after already contains the Portland changes; bookkeeping is added here. */
function papplied(array $after, string $narrative, array $usage): array
{
    $after['location'] ??= null;
    $after['turnCount'] = ($after['turnCount'] ?? 0) + 1;
    $after['lastUpdated'] = 'NOW';
    $after['profile']['actionHistory'][] = ['turn' => $after['turnCount'], 'action' => 'walk north',
        'time' => $after['worldState']['time'], 'location' => $after['location']['name'] ?? 'unknown'];
    return [[
        'type' => 'turn_applied',
        'data' => [
            'gameId' => 'p1',
            'state' => $after,
            'response' => ['narrative' => $narrative, 'suggestedActions' => ['Go'], 'player' => $after['player'],
                'turnCount' => $after['turnCount'], 'currentZone' => $after['currentZone']],
            'usage' => $usage,
            'model' => 'claude-sonnet-4-20250514',
        ],
    ]];
}

test('time: the AI\'s currentTime wins over timeAdvance', function () use ($g, $usage, $pioneer) {
    $s = pstate();
    $out = prun($g, $s, pparsed(['timeAdvance' => 20], ['currentTime' => '06:25']), $usage);
    $after = $s; $after['location'] = $pioneer; $after['worldState']['time'] = '06:25';
    assertEqual($out, papplied($after, 'N.', $usage));
});

test('time: timeAdvance alone advances the clock and wraps past midnight', function () use ($g, $usage, $pioneer) {
    $s = pstate(); $s['worldState']['time'] = '23:50';
    $out = prun($g, $s, pparsed(['timeAdvance' => 20]), $usage);
    $after = $s; $after['location'] = $pioneer; $after['worldState']['time'] = '00:10';
    assertEqual($out, papplied($after, 'N.', $usage));
});

test('landmark: found once, counted, not double-counted', function () use ($g, $usage, $pioneer) {
    $s = pstate(['powells']);
    $out = prun($g, $s, pparsed(['landmarkFound' => 'voodoo']), $usage);
    $after = $s; $after['location'] = $pioneer;
    $after['worldState']['landmarks_visited'] = ['powells', 'voodoo'];
    $after['player']['stats']['landmarks_found'] = 2;
    assertEqual($out, papplied($after, 'N.', $usage));

    $again = prun($g, $s, pparsed(['landmarkFound' => 'powells']), $usage);
    $same = $s; $same['location'] = $pioneer;
    assertEqual($again, papplied($same, 'N.', $usage));
});

test('money and inventory: moneyChange added, items added and removed', function () use ($g, $usage, $pioneer) {
    $s = pstate([], 2.5);
    $out = prun($g, $s, pparsed(['moneyChange' => 5, 'inventoryChanges' => ['add:bus_ticket', 'remove:crumpled_note', 'add:bus_ticket']]), $usage);
    $after = $s; $after['location'] = $pioneer;
    $after['player']['stats']['money'] = 7.5;
    $after['player']['inventory'] = ['bus_ticket'];
    assertEqual($out, papplied($after, 'N.', $usage));
});

test('location: moves the player, sets the zone name, links the new Street View', function () use ($g, $usage) {
    $powells = ['name' => "Powell's City of Books", 'lat' => 45.5231, 'lng' => -122.6815,
        'streetview' => 'https://www.google.com/maps?layer=c&cbll=45.5231,-122.6815'];
    $s = pstate();
    $out = prun($g, $s, pparsed(['location' => $powells, 'flagChanges' => ['met_clerk' => true]], ['streetViewLink' => $powells['streetview']]), $usage);
    $after = $s; $after['location'] = $powells; $after['currentZone'] = "Powell's City of Books";
    $after['worldState']['globalFlags'] = ['met_clerk' => true];
    assertEqual($out, papplied($after, "N.\n\n[View this location]({$powells['streetview']})", $usage));
});

test('win: the tenth landmark sets game_won and appends the win message', function () use ($g, $usage, $pioneer) {
    $nine = ['powells', 'union_station', 'voodoo', 'pittock', 'stjohns', 'lansugarden', 'tabor', 'portlandia', 'hollywood'];
    $s = pstate($nine);
    $out = prun($g, $s, pparsed(['landmarkFound' => 'hawthorne_bridge']), $usage);
    $after = $s; $after['location'] = $pioneer;
    $after['worldState']['landmarks_visited'][] = 'hawthorne_bridge';
    $after['player']['stats']['landmarks_found'] = 10;
    $after['worldState']['globalFlags']['game_won'] = true;
    $narrative = 'N.'
        . "\n\n---\n\n**CONGRATULATIONS!** You've found all 10 landmarks. "
        . "As you say \"I am here\" for the final time, your phone buzzes. "
        . "You look down - wait, you have a phone now? A message reads: "
        . "\"Well done. Check your bank account.\" The game is complete.";
    assertEqual($out, papplied($after, $narrative, $usage));
});

test('bare state gets the Portland defaults; no Street View link unless the AI gives one', function () use ($g, $usage, $pioneer) {
    $out = prun($g, ['currentZone' => 'pioneer_square'], pparsed([]), $usage);
    $after = [
        'currentZone' => 'pioneer_square',
        'worldState' => ['time' => '06:00', 'date' => 'October 15', 'landmarks_visited' => [], 'globalFlags' => []],
        'player' => ['stats' => ['money' => 0, 'landmarks_found' => 0], 'inventory' => []],
        'location' => $pioneer,
        'profile' => ['actionHistory' => [], 'observations' => []],
    ];
    // inventory is created lazily only when an inventory change arrives
    unset($after['player']['inventory']);
    assertEqual($out, papplied($after, 'N.', $usage));
});

test('malformed AI fields are ignored, not saved', function () use ($g, $usage) {
    $s = pstate([], 4);
    $out = prun($g, $s, pparsed([
        'location' => ['name' => ['not', 'a', 'string'], 'lat' => 1],
        'timeAdvance' => 'soon',
        'moneyChange' => '5 dollars',
    ], ['currentTime' => ['h' => 7], 'streetViewLink' => 'javascript:alert(1)']), $usage);
    $after = $s;
    $after['location'] = ['name' => 'Pioneer Courthouse Square', 'lat' => 45.5189, 'lng' => -122.6793,
        'streetview' => 'https://www.google.com/maps?layer=c&cbll=45.5189,-122.6793'];
    assertEqual($out, papplied($after, 'N.', $usage));
});

test('location: only well-typed fields are kept; a bad time string is ignored', function () use ($g, $usage) {
    $s = pstate();
    $out = prun($g, $s, pparsed(['location' => ['name' => 'Voodoo Doughnut', 'lat' => '45.52', 'lng' => -122.673,
        'streetview' => 'not a url', 'crowd' => 'long line']], ['currentTime' => '25:99']), $usage);
    $after = $s; $after['location'] = ['name' => 'Voodoo Doughnut', 'lng' => -122.673]; $after['currentZone'] = 'Voodoo Doughnut';
    assertEqual($out, papplied($after, 'N.', $usage));
});

test('landmark: an id the template does not define is not counted', function () use ($g, $usage, $pioneer) {
    $s = pstate(['powells']);
    $out = prun($g, $s, pparsed(['landmarkFound' => 'fake_landmark']), $usage);
    $after = $s; $after['location'] = $pioneer;
    assertEqual($out, papplied($after, 'N.', $usage));
});

test('landmark: with the template unreadable, no claim can be verified, none is counted', function () use ($g, $usage, $pioneer) {
    $s = pstate();
    $out = prun($g, $s, pparsed(['landmarkFound' => 'powells']), $usage, 'missing');
    $after = $s; $after['location'] = $pioneer;
    assertEqual($out, papplied($after, 'N.', $usage));
});

exit(report('portland_turn_parsed') === 0 ? 0 : 1);
