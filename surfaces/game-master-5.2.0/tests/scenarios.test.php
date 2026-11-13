<?php
/**
 * Scenario tests — the whole stack through the HTTP handlers:
 * handler → driver → gates → file store / mock AI → http_response.
 *
 * These are chain tests. They do not count as, or replace, the
 * per-gate tests in sisoTests/. They run against a temporary copy
 * of data/worlds with a scripted MockAiTransport.
 *
 * Run: php tests/scenarios.test.php
 */

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/runner.php';

use GameMaster\Boundary\AiTransport;
use GameMaster\Boundary\MockAiTransport;
use GameMaster\GameStreamFactory;
use GameMaster\Http\Handlers\GameActionHandler;
use GameMaster\Http\Handlers\GameInitHandler;
use GameMaster\Http\Handlers\GameStateHandler;
use GameMaster\Http\Handlers\ZoneHandler;
use GameMaster\Http\Request;
use GameMaster\Http\Response;
use GameMaster\Jobs\GenerateZoneJob;
use function SisoTest\{test, assertTrue, assertEqual, assertContains, assertNotContains, report};

// ── Sandbox ─────────────────────────────────────────────────────

$root = sys_get_temp_dir() . '/gm-scenarios-' . bin2hex(random_bytes(4));
mkdir("$root/worlds", 0777, true);
foreach (glob(__DIR__ . '/../data/worlds/*.json') as $f) {
    copy($f, "$root/worlds/" . basename($f));
}
// A small world with things in the starting zone, for the inventory guard.
file_put_contents("$root/worlds/test_world.json", json_encode([
    'id' => 'test_world', 'name' => 'Test World', 'theme' => 'test', 'tone' => 'plain',
    'worldRules' => ['Nothing is free'], 'startingZone' => 'hall',
    'zones' => ['hall' => ['description' => 'A bare hall.', 'entities' => [
        ['id' => 'lamp', 'name' => 'Brass Lamp', 'present' => true, 'properties' => ['lit' => false]],
    ]]],
    'playerDefaults' => ['inventory' => ['coin'], 'stats' => ['health' => 100]],
]));
register_shutdown_function(function () use ($root) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($root);
});

$factory = new GameStreamFactory([
    'worldTemplatesPath' => "$root/worlds",
    'savesPath' => "$root/saves",
    'zonesPath' => "$root/zones",
    'useMockAI' => true,
]);
$ai = new MockAiTransport();

$init = new GameInitHandler($factory, false, $ai);
$state = new GameStateHandler($factory, false, $ai);
$act = new GameActionHandler($factory, false, $ai);
$zones = new ZoneHandler($factory, false, $ai);

function status(Response $r): int { return $r->getData()['status']; }
function body(Response $r): array { return json_decode($r->getData()['body'], true); }
function post(array $body): Request { return Request::fromArray(['method' => 'POST', 'body' => $body]); }
function get(array $query = []): Request { return Request::fromArray(['method' => 'GET', 'query' => $query]); }
function lastCall(MockAiTransport $ai): array { $c = $ai->getCalls(); return $c[count($c) - 1]; }
function save(string $root, string $id): array { return json_decode(file_get_contents("$root/saves/game-$id.json"), true); }

// ── Templates and new games ─────────────────────────────────────

test('GET /api/templates lists every world, sorted by id', function () use ($init) {
    $r = $init->listTemplates(get());
    assertEqual(status($r), 200);
    assertEqual(array_column(body($r)['data'], 'id'),
        ['derelict_spaceship', 'portland_scavenger', 'shall_we_play_a_game', 'test_world', 'the_matrix']);
});

test('POST /api/game/new creates the save and answers 201 in the frontend\'s shape', function () use ($init, $root) {
    $r = $init->handle(post(['worldTemplateId' => 'test_world', 'playerName' => 'Ann', 'gameId' => 'scn-1']));
    assertEqual(status($r), 201);
    $b = body($r);
    assertEqual($b['success'], true);
    assertEqual(array_keys($b['data']), ['gameId', 'worldTemplateId', 'narrative', 'currentZone', 'turnCount', 'player', 'suggestedActions']);
    assertEqual($b['data']['gameId'], 'scn-1');
    assertEqual($b['data']['narrative'], 'A bare hall.');
    assertEqual($b['data']['player'], ['name' => 'Ann', 'inventory' => ['coin'], 'stats' => ['health' => 100]]);
    $s = save($root, 'scn-1');
    assertEqual($s['worldTemplateId'], 'test_world');
    assertEqual($s['entities'][0]['id'], 'lamp');
});

test('creating the same game again: 409, unless overwrite', function () use ($init) {
    $r = $init->handle(post(['worldTemplateId' => 'test_world', 'playerName' => 'Ann', 'gameId' => 'scn-1']));
    assertEqual(status($r), 409);
    assertEqual(body($r), ['success' => false, 'error' => "Game 'scn-1' already exists"]);
    $r2 = $init->handle(post(['worldTemplateId' => 'test_world', 'playerName' => 'Bea', 'gameId' => 'scn-1', 'overwrite' => true]));
    assertEqual(status($r2), 201);
    assertEqual(body($r2)['data']['player']['name'], 'Bea');
});

test('new game without an id gets a generated one; unknown template falls back to the default world', function () use ($init, $root) {
    $r = $init->handle(post(['worldTemplateId' => 'no_such_world', 'playerName' => 'Cy']));
    assertEqual(status($r), 201);
    $id = body($r)['data']['gameId'];
    assertTrue(preg_match('/^game-[0-9a-f]{12}$/', $id) === 1, "generated id: {$id}");
    assertEqual(save($root, $id)['worldTemplateId'], 'default');
});

test('bad player name: 422 validation error', function () use ($init) {
    $r = $init->handle(post(['playerName' => '']));
    assertEqual(status($r), 422);
    assertEqual(body($r)['errors'], ['playerName' => 'Player name must be 1-50 characters']);
});

// ── Loading ─────────────────────────────────────────────────────

test('GET /api/game/{id}: summary, full state, not found', function () use ($state) {
    $r = $state->handle(get(), 'scn-1');
    assertEqual(status($r), 200);
    assertEqual(body($r)['data']['suggestedActions'], ['Look around', 'Examine brass lamp', 'Check inventory']);
    assertTrue(!isset(body($r)['data']['gameState']));
    assertEqual(body($state->handle(get(['full' => 'true']), 'scn-1'))['data']['gameState']['gameId'], 'scn-1');
    $nf = $state->handle(get(), 'nobody');
    assertEqual(status($nf), 404);
    assertEqual(body($nf)['error'], "Game 'nobody' not found");
});

// ── Turns ───────────────────────────────────────────────────────

test('a turn: AI changes applied, hallucinated item refused, save and budget updated', function () use ($act, $ai, $root) {
    $ai->setResponses([[
        'narrative' => 'You light the lamp and pocket it. A sword glints — or does it?',
        'stateUpdates' => [
            'entityChanges' => [['entityId' => 'lamp', 'properties' => ['lit' => true]]],
            'inventoryChanges' => ['add:lamp', 'add:sword', 'remove:coin', 'remove:ruby'],
            'statChanges' => ['health' => 95],
        ],
        'suggestedActions' => ['Leave the hall'],
    ]]);
    $r = $act->handle(post(['action' => 'take the lamp']), 'scn-1');
    assertEqual(status($r), 200);
    $b = body($r);
    assertEqual(array_keys($b), ['success', 'data', 'error', 'meta']);
    assertEqual(array_keys($b['data']), ['narrative', 'suggestedActions', 'player', 'turnCount', 'currentZone', 'lastSaved']);
    assertEqual($b['data']['player']['inventory'], ['lamp'], 'lamp added, sword refused, coin removed');
    assertEqual($b['data']['turnCount'], 1);
    assertEqual($b['meta'], ['tokensUsed' => 500, 'turnTokens' => 500]);
    assertTrue(strtotime($b['data']['lastSaved']) !== false, 'lastSaved is the write time');

    $s = save($root, 'scn-1');
    assertEqual($s['entities'][0]['properties']['lit'], true);
    assertEqual($s['player']['stats']['health'], 95);
    assertEqual($s['profile']['actionHistory'], [['turn' => 1, 'action' => 'take the lamp']]);
    assertEqual($s['budget']['sessionTokens'], 500);

    $prompt = $ai->getCalls()[0];
    assertContains('Current contents: [coin]', $prompt['system']);
    assertContains('Current entity IDs: [lamp]', $prompt['system']);
    assertEqual($prompt['user'], 'PLAYER ACTION: take the lamp');
});

test('a second turn: session tokens accumulate across requests; history reaches the prompt', function () use ($act, $ai) {
    $ai->setResponses(['{"narrative": "Quiet."}']);
    $b = body($act->handle(post(['action' => 'wait']), 'scn-1'));
    assertEqual($b['meta'], ['tokensUsed' => 1000, 'turnTokens' => 500]);
    assertEqual($b['data']['turnCount'], 2);
    assertContains("RECENT ACTIONS:\n- Turn 1: take the lamp", lastCall($ai)['user']);
});

test('turn failures answer 422 in the in-world shape', function () use ($act, $ai) {
    $v = $act->handle(post(['action' => '']), 'scn-1');
    assertEqual([status($v), body($v)['errors']], [422, ['action' => 'Action is required']]);

    $nf = $act->handle(post(['action' => 'look']), 'ghost-game');
    assertEqual(status($nf), 422);
    assertEqual(body($nf)['data']['narrative'], 'Game not found: ghost-game');

    $ai->forceError('AI_REQUEST_FAILED', 'Rate limited (429)');
    $f = $act->handle(post(['action' => 'look']), 'scn-1');
    $ai->clearError();
    assertEqual(status($f), 422);
    assertEqual(body($f)['data']['narrative'], 'Something unexpected happens. (Rate limited (429))');
    assertEqual(body($f)['data']['suggestedActions'], ['Try your action again']);

    $bad = $act->handle(post(['action' => 'look']), '../saves/game-scn-1');
    assertEqual([status($bad), body($bad)['error']], [400, 'Invalid game ID']);
});

test('Portland: location, clock, money and landmark are applied; Street View linked', function () use ($init, $act, $ai, $root) {
    assertEqual(status($init->handle(post(['worldTemplateId' => 'portland_scavenger', 'playerName' => 'Sam', 'gameId' => 'pdx']))), 201);
    $ai->setResponses([[
        'narrative' => "You reach Powell's and say \"I am here\".",
        'stateUpdates' => [
            'location' => ['name' => "Powell's City of Books", 'lat' => 45.5231, 'lng' => -122.6815],
            'timeAdvance' => 15, 'moneyChange' => 2, 'landmarkFound' => 'powells',
        ],
        'currentTime' => '06:15',
        'streetViewLink' => 'https://www.google.com/maps?layer=c&cbll=45.5231,-122.6815',
        'suggestedActions' => ['Read the next clue'],
    ]]);
    $b = body($act->handle(post(['action' => 'walk to powells and say I am here']), 'pdx'));
    assertEqual($b['success'], true);
    assertEqual($b['data']['currentZone'], "Powell's City of Books");
    assertContains('[View this location](https://www.google.com/maps?layer=c&cbll=45.5231,-122.6815)', $b['data']['narrative']);
    $s = save($root, 'pdx');
    assertEqual($s['worldState']['time'], '06:15');
    assertEqual($s['player']['stats']['money'], 2);
    assertEqual($s['worldState']['landmarks_visited'], ['powells']);
    assertEqual($s['player']['stats']['landmarks_found'], 1);
    assertContains('realistic urban survival scavenger hunt set in Portland', lastCall($ai)['system']);
});

// ── Zones ───────────────────────────────────────────────────────

$caveJson = ['name' => 'Dark Cave', 'description' => 'Water drips.', 'entities' => [['id' => 'bats', 'name' => 'Bats']],
    'exits' => [
        ['direction' => 'north', 'targetZoneId' => 'tunnel'],
        ['direction' => 'east', 'targetZoneId' => null],
        ['direction' => 'south', 'targetZoneId' => 'cave_mouth'],
    ],
    'properties' => ['lighting' => 'dark', 'danger_level' => 3]];

test('zone generate: 201, zone file, index and save summary written', function () use ($zones, $ai, $root, $caveJson) {
    $ai->setResponses([$caveJson]);
    $r = $zones->generate(post(['zoneId' => 'cave', 'direction' => 'down']), 'scn-1');
    assertEqual(status($r), 201);
    assertEqual(body($r)['data']['name'], 'Dark Cave');
    assertTrue(is_file("$root/zones/scn-1/cave.json"));
    assertEqual(array_keys(json_decode(file_get_contents("$root/zones/scn-1/_index.json"), true)), ['cave']);
    assertEqual(save($root, 'scn-1')['zones']['cave']['name'], 'Dark Cave');
    assertContains('Generate zone with ID: cave', lastCall($ai)['user']);
});

test('zone generate: 409 for an existing zone, 404 for an unknown game, 422 without an id', function () use ($zones) {
    assertEqual(status($zones->generate(post(['zoneId' => 'cave']), 'scn-1')), 409);
    assertEqual(status($zones->generate(post(['zoneId' => 'cave']), 'ghost-game')), 404);
    assertEqual(status($zones->generate(post([]), 'scn-1')), 422);
});

test('zone pregenerate: every open exit generated, one batch write keeps all of them', function () use ($zones, $ai, $root) {
    $ai->setResponses([
        fn(array $req) => json_encode(['name' => 'Tunnel', 'description' => 'Narrow.', 'exits' => []]),
        'not json at all',   // → fallback zone, still stored
    ]);
    $r = $zones->pregenerate(post(['fromZoneId' => 'cave']), 'scn-1');
    assertEqual(status($r), 200);
    $d = body($r)['data'];
    assertEqual($d['requested'], 2);
    assertEqual($d['generated'], [['zoneId' => 'tunnel', 'name' => 'Tunnel'], ['zoneId' => 'cave_mouth', 'name' => 'Southern Mouth']]);
    assertEqual($d['errors'], []);
    $s = save($root, 'scn-1');
    assertEqual(array_keys($s['zones']), ['cave', 'tunnel', 'cave_mouth'], 'no lost update between the two zones');
    assertEqual(array_keys(json_decode(file_get_contents("$root/zones/scn-1/_index.json"), true)), ['cave', 'tunnel', 'cave_mouth']);

    $again = body($zones->pregenerate(post(['fromZoneId' => 'cave']), 'scn-1'))['data'];
    assertEqual([$again['requested'], $again['generated']], [0, []], 'already generated zones are skipped');
});

test('zone pregenerate: an AI failure on one branch is reported, the others stored', function () use ($factory, $root) {
    $failing = new class implements AiTransport {
        public function call(array $request): array {
            if ($request['carry']['zoneId'] === 'room_b') {
                return ['ok' => false, 'content' => null, 'usage' => ['input' => 0, 'output' => 0], 'model' => 'm',
                    'error' => ['code' => 'AI_REQUEST_FAILED', 'message' => 'Overloaded (529)']];
            }
            return ['ok' => true, 'content' => json_encode(['name' => ucfirst($request['carry']['zoneId'])]), 'usage' => ['input' => 1, 'output' => 1], 'model' => 'm', 'error' => null];
        }
    };
    $h = new ZoneHandler($factory, false, $failing);
    is_dir("$root/zones/scn-1") || mkdir("$root/zones/scn-1", 0777, true);
    file_put_contents("$root/zones/scn-1/hub.json", json_encode(['name' => 'Hub', 'exits' => [
        ['direction' => 'north', 'targetZoneId' => 'room_a'], ['direction' => 'south', 'targetZoneId' => 'room_b']]]));
    $d = body($h->pregenerate(post(['fromZoneId' => 'hub']), 'scn-1'))['data'];
    assertEqual($d['generated'], [['zoneId' => 'room_a', 'name' => 'Room_a']]);
    assertEqual($d['errors'], [['zoneId' => 'room_b', 'error' => 'Overloaded (529)']]);
    assertTrue(!is_file("$root/zones/scn-1/room_b.json"));
});

test('zone pregenerate never overwrites a zone file the index does not list', function () use ($zones, $ai, $root) {
    file_put_contents("$root/zones/scn-1/keep.json", json_encode(['name' => 'HANDMADE KEEP']));
    file_put_contents("$root/zones/scn-1/gate.json", json_encode(['name' => 'Gate', 'exits' => [['direction' => 'up', 'targetZoneId' => 'keep']]]));
    $ai->setResponses([['name' => 'Generated Keep']]);
    $d = body($zones->pregenerate(post(['fromZoneId' => 'gate']), 'scn-1'))['data'];
    assertEqual($d['generated'], []);
    assertEqual($d['errors'], [['zoneId' => 'keep', 'error' => "Zone 'keep' already exists"]]);
    assertEqual(json_decode(file_get_contents("$root/zones/scn-1/keep.json"), true)['name'], 'HANDMADE KEEP');
    unlink("$root/zones/scn-1/keep.json");
    unlink("$root/zones/scn-1/gate.json");
});

test('zone get, list, delete', function () use ($zones) {
    assertEqual(body($zones->get(get(), 'scn-1', 'tunnel'))['data']['name'], 'Tunnel');
    assertEqual(body($zones->list(get(), 'scn-1'))['data']['count'], 4);
    $del = $zones->delete(Request::fromArray(['method' => 'DELETE']), 'scn-1', 'tunnel');
    assertEqual([status($del), body($del)['data']], [200, ['deleted' => 'tunnel']]);
    assertEqual(status($zones->get(get(), 'scn-1', 'tunnel')), 404);
    assertEqual(body($zones->list(get(), 'scn-1'))['data']['count'], 3);
    assertEqual(status($zones->delete(Request::fromArray(['method' => 'DELETE']), 'scn-1', 'tunnel')), 404);
});

test('path characters never reach the filesystem', function () use ($zones, $root) {
    assertEqual(status($zones->get(get(), 'scn-1', '..')), 404);
    assertEqual(status($zones->delete(Request::fromArray(['method' => 'DELETE']), 'scn-1', '../../saves/game-scn-1')), 404);
    assertEqual(status($zones->list(get(), '..')), 400);
    assertTrue(is_file("$root/saves/game-scn-1.json"), 'save untouched');
});

// ── Background job ──────────────────────────────────────────────

test('zone job: queued, processed through the same flow, moved to _completed', function () use ($factory, $root) {
    $ai = new MockAiTransport([['name' => 'Vault', 'description' => 'Cold.']]);
    $job = new GenerateZoneJob($factory, "$root/queue", $ai);
    $jobId = $job->queue('scn-1', 'vault', 'cave', 'down');
    $results = $job->processQueue(5);
    assertEqual($results[$jobId]['success'], true);
    assertEqual($results[$jobId]['zoneName'], 'Vault');
    assertTrue(is_file("$root/queue/_completed/{$jobId}.json"));
    assertTrue(is_file("$root/zones/scn-1/vault.json"));

    $dupId = $job->queue('scn-1', 'vault');
    $dup = $job->processQueue(5)[$dupId];
    assertEqual($dup, ['success' => false, 'error' => "Zone 'vault' already exists"]);
});

exit(report('scenarios') === 0 ? 0 : 1);
