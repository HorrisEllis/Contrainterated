<?php
/** GameSnapshotGate — turn_accounted → io_write {save}. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('turn_accounted');

test('accepted: the new state is written; response and meta ride along', function () use ($g) {
    $state = ['gameId' => 'g1', 'turnCount' => 4];
    $response = ['narrative' => 'n'];
    $meta = ['tokensUsed' => 9, 'turnTokens' => 3];
    assertEqual(run($g, ['gameId' => 'g1', 'state' => $state, 'response' => $response, 'meta' => $meta]), [[
        'type' => 'io_write',
        'data' => [
            'writes' => ['save' => ['kind' => 'save', 'gameId' => 'g1', 'op' => 'put', 'content' => $state]],
            'reply' => 'turn_saved',
            'carry' => ['response' => $response, 'meta' => $meta],
        ],
    ]]);
});

exit(report('turn_accounted') === 0 ? 0 : 1);
