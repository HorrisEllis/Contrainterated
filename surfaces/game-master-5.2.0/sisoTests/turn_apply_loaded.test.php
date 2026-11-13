<?php
/** TurnApplyRouteGate — turn_apply_loaded → generic_turn_parsed | io_read {template} → portland_turn_parsed; rejects a vanished save (turn_failed). */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('turn_apply_loaded');
$carry = ['gameId' => 'g1', 'action' => 'look', 'now' => 'T', 'parsed' => ['narrative' => 'n'],
    'usage' => ['input' => 1, 'output' => 2], 'model' => 'm'];

test('accepted (class generic): generic world', function () use ($g, $carry) {
    $state = ['worldTemplateId' => 'the_matrix'];
    assertEqual(run($g, $carry + ['reads' => reads(['save' => $state])]),
        [['type' => 'generic_turn_parsed', 'data' => $carry + ['state' => $state]]]);
});

test('accepted (class generic): world not recorded', function () use ($g, $carry) {
    assertEqual(run($g, $carry + ['reads' => reads(['save' => []])]),
        [['type' => 'generic_turn_parsed', 'data' => $carry + ['state' => []]]]);
});

test('accepted (class portland): Portland world — its gate also gets the template', function () use ($g, $carry) {
    $state = ['worldTemplateId' => 'portland_scavenger'];
    assertEqual(run($g, $carry + ['reads' => reads(['save' => $state])]), [[
        'type' => 'io_read',
        'data' => ['reads' => ['template' => ['kind' => 'template', 'templateId' => 'portland_scavenger']],
            'reply' => 'portland_turn_parsed', 'carry' => $carry + ['state' => $state]],
    ]]);
});

test('rejected (abort): save gone', function () use ($g, $carry) {
    assertEqual(run($g, $carry + ['reads' => reads(['save' => 'missing'])]), [[
        'type' => 'turn_failed',
        'data' => ['code' => 'GAME_NOT_FOUND', 'message' => 'Game not found: g1', 'hint' => null],
    ]]);
});

exit(report('turn_apply_loaded') === 0 ? 0 : 1);
