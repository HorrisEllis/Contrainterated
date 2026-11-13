<?php
/** WorldTemplateGate — game_create_loaded → game_template_ready; rejects an invalid template with 400. */

require_once __DIR__ . '/helper.php';

use GameMaster\Gates\WorldTemplateGate;
use function SisoTest\{test, assertEqual, report};

$g = gate('game_create_loaded');

$base = ['gameId' => 'g1', 'worldTemplateId' => 'x', 'playerName' => 'Ann', 'overwrite' => false, 'now' => 'T'];

function ready(array $base, array $template, bool $exists): array
{
    return [[
        'type' => 'game_template_ready',
        'data' => $base + ['template' => $template, 'exists' => $exists],
    ]];
}

$tpl = ['id' => 'x', 'theme' => 'space', 'tone' => 'tense', 'startingZone' => 'bridge', 'zones' => ['bridge' => []]];

test('accepted: the requested template, no existing save', function () use ($g, $base, $tpl) {
    assertEqual(run($g, $base + ['reads' => reads(['template' => $tpl, 'existing' => 'missing'])]),
        ready($base, $tpl, false));
});

test('accepted: an existing save is reported (corrupt counts as existing)', function () use ($g, $base, $tpl) {
    assertEqual(run($g, $base + ['reads' => reads(['template' => $tpl, 'existing' => 'corrupt'])]),
        ready($base, $tpl, true));
});

test('accepted: unreadable template falls back to the built-in default', function () use ($g, $base) {
    assertEqual(run($g, $base + ['reads' => reads(['template' => 'missing', 'existing' => 'missing'])]),
        ready($base, WorldTemplateGate::builtinDefault(), false));
});

test('rejected (abort): template missing theme and tone, starting zone absent', function () use ($g, $base) {
    $bad = ['startingZone' => 'nowhere', 'zones' => ['somewhere' => []]];
    assertEqual(run($g, $base + ['reads' => reads(['template' => $bad, 'existing' => 'missing'])]), [[
        'type' => 'http_response',
        'data' => ['status' => 400, 'body' => ['success' => false, 'error' =>
            "World template validation failed: Missing required field: theme, Missing required field: tone, Starting zone 'nowhere' not found in zones"]],
    ]]);
});

exit(report('game_create_loaded') === 0 ? 0 : 1);
