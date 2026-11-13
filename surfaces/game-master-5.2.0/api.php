<?php
/**
 * Game Master API Entry Point
 *
 * Configure your web server to route all API requests here.
 *
 * Example nginx config:
 *   location /api {
 *       try_files $uri /api.php$is_args$args;
 *   }
 *
 * Example Apache .htaccess:
 *   RewriteEngine On
 *   RewriteCond %{REQUEST_FILENAME} !-f
 *   RewriteRule ^api/(.*)$ api.php [QSA,L]
 */

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/config.php';

// ========== Logging (must be first — catches everything below) ==========

use GameMaster\Logger;

Logger::init(__DIR__ . '/errors.log');

$_requestStart = microtime(true);
Logger::request();

// Early sanity check — log a clear warning now rather than a cryptic
// class-not-found error later when a gate is first instantiated.
$_sisoPath = __DIR__ . '/siso/php/code/core/stream.php';
if (!file_exists($_sisoPath)) {
    Logger::error('SISO core not found at siso/php/code/core/ — all gate operations will fail');
}
unset($_sisoPath);

// Catch PHP fatal errors (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR)
// that cannot be intercepted by a normal try/catch and would otherwise cause
// the process to die silently, producing a 502 from the web server.
register_shutdown_function(function () use (&$_requestStart) {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        Logger::fatal($err);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Internal server error (fatal)']);
        }
    } else {
        // Log normal request completion if not already logged
        $status = http_response_code() ?: 200;
        Logger::response((int)$status, $_requestStart);
    }
});

// Convert PHP warnings and notices into catchable ErrorExceptions so they
// surface in logs and are caught by the Throwable handlers below.
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    Logger::warn("PHP Error [{$severity}]: {$message}", ['file' => "{$file}:{$line}"]);
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

// ========== Imports ==========

use GameMaster\Http\Router;
use GameMaster\Http\Request;
use GameMaster\Http\Response;
use GameMaster\Http\Handlers\GameActionHandler;
use GameMaster\Http\Handlers\GameStateHandler;
use GameMaster\Http\Handlers\GameInitHandler;
use GameMaster\Http\Handlers\ZoneHandler;
use GameMaster\Http\Middleware\CorsMiddleware;
use GameMaster\Http\Middleware\JsonMiddleware;
use GameMaster\Http\Middleware\ErrorMiddleware;
use GameMaster\GameStreamFactory;

// ========== Configuration ==========

// Strip /gamemaster or /scavenger prefix if present (for subdirectory installs)
$_SERVER['REQUEST_URI'] = preg_replace('#^/(gamemaster|scavenger)#', '', $_SERVER['REQUEST_URI']);

$debug = getenv('GAMEMASTER_DEBUG') === 'true' || ($_GET['debug'] ?? false);

$config = [
    'worldTemplatesPath' => __DIR__ . '/data/worlds',
    'savesPath'          => __DIR__ . '/saves',
    'zonesPath'          => __DIR__ . '/data/zones',
    'useMockAI'          => getenv('GAMEMASTER_MOCK_AI') === 'true' || !getAnthropicApiKey(),
    'aiKey'              => getAnthropicApiKey(),
    'aiModel'            => getAnthropicModel('claude-sonnet-4-20250514'),
    'maxZonePreGenerate' => 3,
    'debug'              => $debug,
];

Logger::debug('Config loaded', [
    'useMockAI' => $config['useMockAI'],
    'aiModel'   => $config['aiModel'],
    'debug'     => $debug,
    'worldTemplatesPath' => $config['worldTemplatesPath'],
]);

// ========== Setup ==========

$factory = new GameStreamFactory($config);

$actionHandler = new GameActionHandler($factory, $debug);
$stateHandler = new GameStateHandler($factory, $debug);
$initHandler = new GameInitHandler($factory, $debug);
$zoneHandler = new ZoneHandler($factory, $debug);

// ========== Router ==========

$router = new Router();

// Add middleware
$router->use(new CorsMiddleware([
    'origins' => ['*'],
    'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    'headers' => ['Content-Type', 'Authorization'],
]));

$router->use(new JsonMiddleware());

// ========== Routes ==========

// Health check — probes every subsystem that can fail at runtime
$router->get('/api/health', function (Request $request) use ($config) {
    $checks  = [];
    $healthy = true;

    // 1. SISO core (vendored in-tree at siso/)
    $sisoPath = __DIR__ . '/siso/php/code/core/stream.php';
    $checks['siso_framework'] = file_exists($sisoPath)
        ? ['ok' => true, 'location' => 'siso/', 'version' => trim((string) @file_get_contents(__DIR__ . '/siso/VERSION'))]
        : ['ok' => false, 'error' => 'siso/php/code/core/stream.php not found — core missing'];

    // 2. World templates
    $worldsPath = $config['worldTemplatesPath'];
    $worldFiles = is_dir($worldsPath) ? glob("{$worldsPath}/*.json") : [];
    $checks['worlds'] = is_dir($worldsPath)
        ? ['ok' => true, 'count' => count($worldFiles)]
        : ['ok' => false, 'error' => "Worlds directory not found: {$worldsPath}"];

    // 3. Saves directory writable
    $savesPath = $config['savesPath'];
    $checks['saves_dir'] = (is_dir($savesPath) && is_writable($savesPath))
        ? ['ok' => true]
        : ['ok' => false, 'error' => "Saves directory missing or not writable: {$savesPath}"];

    // 4. Zones directory writable
    $zonesPath = $config['zonesPath'];
    $checks['zones_dir'] = (is_dir($zonesPath) && is_writable($zonesPath))
        ? ['ok' => true]
        : ['ok' => false, 'error' => "Zones directory missing or not writable: {$zonesPath}"];

    // 5. AI key present
    $checks['ai_key'] = !empty($config['aiKey'])
        ? ['ok' => true, 'mock' => $config['useMockAI']]
        : ['ok' => false, 'error' => 'No AI key configured — set ANTHROPIC_API_KEY or keys.json', 'mock' => true];

    // 6. PHP version
    $checks['php'] = version_compare(PHP_VERSION, '8.1.0', '>=')
        ? ['ok' => true, 'version' => PHP_VERSION]
        : ['ok' => false, 'error' => 'PHP 8.1+ required, got ' . PHP_VERSION];

    // Roll up
    foreach ($checks as $k => $c) {
        if (!$c['ok']) {
            $healthy = false;
            Logger::warn("Health check failed: {$k}", $c);
        }
    }

    $status   = $healthy ? 'ok' : 'degraded';
    $httpCode = $healthy ? 200  : 503;

    return (new Response())->status($httpCode)->json([
        'success'   => $healthy,
        'status'    => $status,
        'timestamp' => date('c'),
        'checks'    => $checks,
    ]);
});

// List world templates
$router->get('/api/templates', function (Request $request) use ($initHandler) {
    return $initHandler->listTemplates($request);
});

// Create new game
$router->post('/api/game/new', function (Request $request) use ($initHandler) {
    return $initHandler->handle($request);
});

// Get game state
$router->get('/api/game/{gameId}', function (Request $request, string $gameId) use ($stateHandler) {
    return $stateHandler->handle($request, $gameId);
});

// Process player action
$router->post('/api/game/{gameId}/action', function (Request $request, string $gameId) use ($actionHandler) {
    return $actionHandler->handle($request, $gameId);
});

// Shorthand action endpoint (game ID in body)
$router->post('/api/action', function (Request $request) use ($actionHandler) {
    // A missing gameId is rejected by ActionParseGate (422).
    $gameId = $request->input('gameId', '');
    return $actionHandler->handle($request, is_scalar($gameId) ? (string) $gameId : '');
});

// ========== Zone Endpoints ==========

// Generate a new zone
$router->post('/api/game/{gameId}/zone/generate', function (Request $request, string $gameId) use ($zoneHandler) {
    return $zoneHandler->generate($request, $gameId);
});

// Trigger pre-generation of adjacent zones
$router->post('/api/game/{gameId}/zone/pregenerate', function (Request $request, string $gameId) use ($zoneHandler) {
    return $zoneHandler->pregenerate($request, $gameId);
});

// Get zone details
$router->get('/api/game/{gameId}/zone/{zoneId}', function (Request $request, string $gameId, string $zoneId) use ($zoneHandler) {
    return $zoneHandler->get($request, $gameId, $zoneId);
});

// List all zones
$router->get('/api/game/{gameId}/zones', function (Request $request, string $gameId) use ($zoneHandler) {
    return $zoneHandler->list($request, $gameId);
});

// Delete a zone
$router->delete('/api/game/{gameId}/zone/{zoneId}', function (Request $request, string $gameId, string $zoneId) use ($zoneHandler) {
    return $zoneHandler->delete($request, $gameId, $zoneId);
});

// API info
$router->get('/api', function (Request $request) use ($router, $config) {
    return Response::success([
        'name' => 'Game Master API',
        'version' => '1.0.0',
        'phase' => 4,
        'mockAI' => $config['useMockAI'],
        'endpoints' => [
            'GET /api/health' => 'Health check',
            'GET /api/templates' => 'List world templates',
            'POST /api/game/new' => 'Create new game',
            'GET /api/game/{gameId}' => 'Get game state',
            'POST /api/game/{gameId}/action' => 'Process action',
            'POST /api/action' => 'Process action (gameId in body)',
            'POST /api/game/{gameId}/zone/generate' => 'Generate a zone',
            'POST /api/game/{gameId}/zone/pregenerate' => 'Pre-generate adjacent zones',
            'GET /api/game/{gameId}/zone/{zoneId}' => 'Get zone details',
            'GET /api/game/{gameId}/zones' => 'List all zones',
            'DELETE /api/game/{gameId}/zone/{zoneId}' => 'Delete a zone',
        ],
    ]);
});

// ========== Run ==========

try {
    $router->run();
} catch (\Throwable $e) {
    Logger::exception($e, 'api.php top-level');
    $errorMiddleware = new ErrorMiddleware($debug);
    $response = $errorMiddleware->handleException($e);
    $response->send();
}
