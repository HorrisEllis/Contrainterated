<?php
/**
 * Probe for departure G2 (doc/conformance.md): an AI reply whose
 * statChanges names a worldState key the game uses itself (globalFlags)
 * is saved, and every later turn that sets a flag throws in
 * StateUpdateGate. Found by the 5.2.0 independent review; present in
 * 5.1.0 too.
 *
 * Not a test and not in run_tests.php. Plays 3 turns in a temp dir
 * through the handlers (scripted mock AI, warnings as exceptions as in
 * production) and prints the HTTP status of each.
 *
 *   php tests/probes/g2-stat-keys.php
 * Last line: g2-probe: statuses 200,500,500   (200,200,200 once G2 is fixed)
 */

require_once __DIR__ . '/../../autoload.php';

use GameMaster\Boundary\MockAiTransport;
use GameMaster\GameStreamFactory;
use GameMaster\Http\Handlers\GameActionHandler;
use GameMaster\Http\Handlers\GameInitHandler;
use GameMaster\Http\Request;
use GameMaster\Logger;

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

$dir = sys_get_temp_dir() . '/g2-probe-' . bin2hex(random_bytes(4));
Logger::init("$dir/errors.log");
$factory = new GameStreamFactory(['savesPath' => "$dir/saves", 'zonesPath' => "$dir/zones", 'useMockAI' => true]);
$post = fn(array $body) => Request::fromArray(['method' => 'POST', 'body' => $body]);
$ai = new MockAiTransport([
    ['narrative' => 'n1', 'stateUpdates' => ['statChanges' => ['globalFlags' => 'x']]],
    ['narrative' => 'n2', 'stateUpdates' => ['flagChanges' => ['door_open' => true]]],
    ['narrative' => 'n3', 'stateUpdates' => ['flagChanges' => ['door_open' => false]]],
]);
(new GameInitHandler($factory, false, $ai))->handle($post(['worldTemplateId' => 'derelict_spaceship', 'playerName' => 'A', 'gameId' => 'g2probe']));
$handler = new GameActionHandler($factory, false, $ai);
$statuses = [];
foreach (['look', 'open door', 'close door'] as $action) {
    $statuses[] = $handler->handle($post(['action' => $action]), 'g2probe')->getData()['status'];
}
exec('rm -rf ' . escapeshellarg($dir));
echo "g2-probe: statuses " . implode(',', $statuses) . "\n";
