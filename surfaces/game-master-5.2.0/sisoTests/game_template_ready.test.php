<?php
/** GameInitGate — game_template_ready → io_write {save}; rejects an existing game with 409. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('game_template_ready');

$template = [
    'id' => 'derelict_spaceship', 'theme' => 'sci-fi', 'tone' => 'eerie', 'worldRules' => ['Air is scarce'],
    'startingZone' => 'airlock',
    'zones' => ['airlock' => ['description' => 'A cold airlock.', 'entities' => [['id' => 'panel', 'name' => 'Panel']]]],
    'playerDefaults' => ['stats' => ['oxygen' => 90], 'inventory' => ['torch']],
];

function expectedWrite(string $gameId, array $state, array $suggested): array
{
    return [[
        'type' => 'io_write',
        'data' => [
            'writes' => ['save' => ['kind' => 'save', 'gameId' => $gameId, 'op' => 'put', 'content' => $state]],
            'reply' => 'game_created',
            'carry' => [
                'gameId' => $gameId,
                'worldTemplateId' => 'derelict_spaceship',
                'narrative' => $state['zoneDescription'],
                'currentZone' => $state['currentZone'],
                'player' => $state['player'],
                'suggestedActions' => $suggested,
            ],
        ],
    ]];
}

$state = [
    'gameId' => 'g1', 'worldTemplateId' => 'derelict_spaceship', 'created' => 'T', 'lastUpdated' => 'T',
    'turnCount' => 0, 'currentZone' => 'airlock', 'zoneDescription' => 'A cold airlock.',
    'player' => ['name' => 'Ann', 'inventory' => ['torch'], 'stats' => ['oxygen' => 90]],
    'entities' => [['id' => 'panel', 'name' => 'Panel']],
    'worldState' => ['day' => 1, 'time' => 'morning', 'globalFlags' => []],
    'profile' => ['actionHistory' => [], 'observations' => [], 'patterns' => []],
    'worldTemplate' => ['theme' => 'sci-fi', 'tone' => 'eerie', 'worldRules' => ['Air is scarce']],
];
$defaultSuggestions = ['Look around', 'Check inventory', 'Examine your surroundings'];

test('accepted: new game — initial state from the template', function () use ($g, $template, $state, $defaultSuggestions) {
    $out = run($g, ['gameId' => 'g1', 'worldTemplateId' => 'derelict_spaceship', 'playerName' => 'Ann',
        'overwrite' => false, 'now' => 'T', 'template' => $template, 'exists' => false]);
    assertEqual($out, expectedWrite('g1', $state, $defaultSuggestions));
});

test('accepted: existing game with overwrite; startingDescription and suggestions win', function () use ($g, $template, $state) {
    $t = $template + ['startingDescription' => 'You wake.', 'startingSuggestions' => ['Breathe']];
    $s = array_replace($state, ['zoneDescription' => 'You wake.']);
    $out = run($g, ['gameId' => 'g1', 'worldTemplateId' => 'derelict_spaceship', 'playerName' => 'Ann',
        'overwrite' => true, 'now' => 'T', 'template' => $t, 'exists' => true]);
    assertEqual($out, expectedWrite('g1', $s, ['Breathe']));
});

test('rejected (abort): game exists and overwrite is off', function () use ($g, $template) {
    $out = run($g, ['gameId' => 'g1', 'worldTemplateId' => 'derelict_spaceship', 'playerName' => 'Ann',
        'overwrite' => false, 'now' => 'T', 'template' => $template, 'exists' => true]);
    assertEqual($out, [[
        'type' => 'http_response',
        'data' => ['status' => 409, 'body' => ['success' => false, 'error' => "Game 'g1' already exists"]],
    ]]);
});

exit(report('game_template_ready') === 0 ? 0 : 1);
