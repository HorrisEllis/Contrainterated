<?php
/** TemplateCatalogGate — templates_loaded → http_response 200 with the template summaries. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('templates_loaded');

test('accepted: summaries sorted by id, with defaults for missing fields', function () use ($g) {
    $out = run($g, ['reads' => reads(['catalog' => [
        'the_matrix' => ['name' => 'The Matrix', 'theme' => 'cyberpunk', 'description' => 'Red pill.'],
        'bare' => [],
        'broken' => 'not-an-array',
    ]])]);
    assertEqual($out, [[
        'type' => 'http_response',
        'data' => ['status' => 200, 'body' => ['success' => true, 'data' => [
            ['id' => 'bare', 'name' => 'bare', 'theme' => 'unknown', 'description' => ''],
            ['id' => 'the_matrix', 'name' => 'The Matrix', 'theme' => 'cyberpunk', 'description' => 'Red pill.'],
        ]]],
    ]]);
});

test('accepted: an unreadable catalog lists nothing', function () use ($g) {
    assertEqual(run($g, ['reads' => reads(['catalog' => 'missing'])]), [[
        'type' => 'http_response',
        'data' => ['status' => 200, 'body' => ['success' => true, 'data' => []]],
    ]]);
});

exit(report('templates_loaded') === 0 ? 0 : 1);
