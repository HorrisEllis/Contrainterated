<?php
/** TurnRouteGate — turn_state_loaded → io_read {template} naming the world's context gate; rejects a missing save (422). */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('turn_state_loaded');
$action = 'look';

function templateRead(string $templateId, string $reply, array $carry): array
{
    return [[
        'type' => 'io_read',
        'data' => ['reads' => ['template' => ['kind' => 'template', 'templateId' => $templateId]], 'reply' => $reply, 'carry' => $carry],
    ]];
}

test('accepted (class generic): generic world → turn_template_loaded', function () use ($g, $action) {
    $state = ['worldTemplateId' => 'the_matrix', 'turnCount' => 1];
    assertEqual(run($g, ['gameId' => 'g1', 'action' => $action, 'now' => 'T', 'reads' => reads(['save' => $state])]),
        templateRead('the_matrix', 'turn_template_loaded', ['gameId' => 'g1', 'action' => $action, 'now' => 'T', 'state' => $state]));
});

test('accepted (class generic): no world recorded → "default" template', function () use ($g, $action) {
    $state = ['turnCount' => 0];
    assertEqual(run($g, ['gameId' => 'g1', 'action' => $action, 'now' => 'T', 'reads' => reads(['save' => $state])]),
        templateRead('default', 'turn_template_loaded', ['gameId' => 'g1', 'action' => $action, 'now' => 'T', 'state' => $state]));
});

test('accepted (class portland): Portland world → portland_turn_loaded', function () use ($g, $action) {
    $state = ['worldTemplateId' => 'portland_scavenger'];
    assertEqual(run($g, ['gameId' => 'p1', 'action' => $action, 'now' => 'T', 'reads' => reads(['save' => $state])]),
        templateRead('portland_scavenger', 'portland_turn_loaded', ['gameId' => 'p1', 'action' => $action, 'now' => 'T', 'state' => $state]));
});

$notFound = [[
    'type' => 'http_response',
    'data' => ['status' => 422, 'body' => [
        'success' => false,
        'data' => ['narrative' => 'Game not found: g9', 'suggestedActions' => [], 'player' => [], 'turnCount' => 0, 'currentZone' => '', 'lastSaved' => null],
        'error' => 'Game not found: g9',
        'meta' => ['tokensUsed' => 0, 'turnTokens' => 0],
    ]],
]];

test('rejected (abort): save missing', function () use ($g, $action, $notFound) {
    assertEqual(run($g, ['gameId' => 'g9', 'action' => $action, 'now' => 'T', 'reads' => reads(['save' => 'missing'])]), $notFound);
});

test('rejected (abort): save corrupt', function () use ($g, $action, $notFound) {
    assertEqual(run($g, ['gameId' => 'g9', 'action' => $action, 'now' => 'T', 'reads' => reads(['save' => 'corrupt'])]), $notFound);
});

exit(report('turn_state_loaded') === 0 ? 0 : 1);
