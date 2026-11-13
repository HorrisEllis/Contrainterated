<?php
/** TemplateListGate — templates_requested → io_read {catalog}. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('templates_requested');

test('accepted: asks for the whole template catalog', function () use ($g) {
    assertEqual(run($g, []), [[
        'type' => 'io_read',
        'data' => ['reads' => ['catalog' => ['kind' => 'templates']], 'reply' => 'templates_loaded', 'carry' => []],
    ]]);
});

exit(report('templates_requested') === 0 ? 0 : 1);
