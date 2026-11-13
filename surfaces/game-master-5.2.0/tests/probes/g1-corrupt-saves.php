<?php
/**
 * Probe for departure G1 (doc/conformance.md): server gates throw on a
 * save file that is valid JSON but has the wrong field types.
 *
 * Not a test and not in run_tests.php: it measures a known departure.
 * It writes 8 type-corrupted saves into a temp dir and runs 4 flows on
 * each through the driver (scripted mock AI), printing each answer or
 * the core's rejection. Run it with and without --warnings: with it,
 * PHP warnings and notices become exceptions, as api.php and worker.php
 * arrange in production, so a notice inside a gate is a throw too.
 *
 *   php tests/probes/g1-corrupt-saves.php [--warnings]
 * Last line: g1-probe: R of N flows rejected
 * When G1 is fixed, R is 0 in both modes.
 */

require_once __DIR__ . '/../../autoload.php';

use GameMaster\GameStreamFactory;
use GameMaster\Boundary\MockAiTransport;
use Siso\Core\Event;
use Siso\Core\Rejection;

if (in_array('--warnings', $argv, true)) {
    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
}

$dir = sys_get_temp_dir() . '/g1-probe-' . bin2hex(random_bytes(4));
mkdir("$dir/saves", 0777, true);
$factory = new GameStreamFactory(['savesPath' => "$dir/saves", 'zonesPath' => "$dir/zones", 'useMockAI' => true]);

$saves = [
    'player string'     => ['worldTemplateId' => 'derelict_spaceship', 'player' => 'x', 'turnCount' => 1],
    'inventory string'  => ['worldTemplateId' => 'derelict_spaceship', 'player' => ['name' => 'a', 'inventory' => 'x', 'stats' => 'y']],
    'entities scalar'   => ['worldTemplateId' => 'derelict_spaceship', 'entities' => 5, 'worldState' => 'w', 'profile' => 'p'],
    'world id array'    => ['worldTemplateId' => ['x']],
    'currentZone array' => ['worldTemplateId' => 'derelict_spaceship', 'currentZone' => ['x']],
    'portland junk'     => ['worldTemplateId' => 'portland_scavenger', 'location' => 'x', 'worldState' => ['time' => [1], 'date' => 3], 'player' => ['stats' => ['money' => 'lots']]],
    'turnCount string'  => ['worldTemplateId' => 'derelict_spaceship', 'turnCount' => 'many', 'budget' => 'b', 'zones' => 'z'],
    'empty object'      => [],
];
$reply = ['narrative' => 'ok', 'stateUpdates' => ['inventoryChanges' => ['add:terminal', 'remove:x'], 'statChanges' => ['health' => 1],
    'entityChanges' => [['entityId' => 'terminal', 'properties' => ['a' => 1]]]], 'landmarkFound' => 'powells', 'moneyChange' => 5, 'timeAdvance' => 10];

$flows = 0;
$rejected = 0;
$i = 0;
foreach ($saves as $name => $save) {
    $id = 'g1probe' . (++$i);
    file_put_contents("$dir/saves/game-$id.json", json_encode(['gameId' => $id] + $save));
    $now = '2026-10-20T10:00:00+00:00';
    foreach ([
        ['action_submitted', ['gameId' => $id, 'action' => 'go', 'now' => $now]],
        ['game_load_requested', ['gameId' => $id, 'full' => true]],
        ['zone_pregenerate_requested', ['gameId' => $id, 'fromZoneId' => 'airlock', 'now' => $now]],
        ['zone_generate_requested', ['gameId' => $id, 'zoneId' => 'newz', 'parentZoneId' => 'airlock', 'direction' => 'north', 'now' => $now]],
    ] as [$type, $data]) {
        $flows++;
        try {
            $out = $factory->createDriver(new MockAiTransport([$reply, $reply, $reply, $reply]))->run(new Event($type, $data));
            $resp = array_values(array_filter($out, fn($e) => $e->type === 'http_response'));
            printf("  %-18s %-28s %s\n", $name, $type, $resp ? 'HTTP ' . $resp[0]->data['status'] : 'no answer');
        } catch (Rejection $e) {
            $rejected++;
            printf("  %-18s %-28s REJECTED %s at %s: %s\n", $name, $type, $e->code, $e->eventType, $e->cause?->getMessage());
        }
    }
}
exec('rm -rf ' . escapeshellarg($dir));
echo "g1-probe: {$rejected} of {$flows} flows rejected\n";
