<?php
/**
 * GameBudgetGate — turn_applied → turn_accounted (session totals in state.budget).
 * Expected costs are computed here from Anthropic's published base rates
 * (https://platform.claude.com/docs/en/about-claude/pricing, checked 2026-09-27),
 * not from the gate's table: Sonnet 4 $3 in / $15 out, Haiku 4.5 $1 / $5,
 * Opus 4.5 $5 / $25 per million tokens.
 */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('turn_applied');
$response = ['narrative' => 'n', 'turnCount' => 2];

function accounted(array $state, array $response, int $session, int $turn): array
{
    return [[
        'type' => 'turn_accounted',
        'data' => ['gameId' => 'g1', 'state' => $state, 'response' => $response, 'meta' => ['tokensUsed' => $session, 'turnTokens' => $turn]],
    ]];
}

test('accepted: first turn — Sonnet pricing', function () use ($g, $response) {
    // 1,000,000 in × $3/M + 200,000 out × $15/M = $3 + $3 = $6
    $out = run($g, ['gameId' => 'g1', 'state' => ['turnCount' => 2], 'response' => $response,
        'usage' => ['input' => 1000000, 'output' => 200000], 'model' => 'claude-sonnet-4-20250514']);
    assertEqual($out, accounted(['turnCount' => 2, 'budget' => ['sessionTokens' => 1200000, 'sessionCost' => 6.0, 'lastTurnTokens' => 1200000]],
        $response, 1200000, 1200000));
});

test('accepted: later turn accumulates onto the saved totals — Haiku 4.5 pricing', function () use ($g, $response) {
    // 500,000 × $1/M + 100,000 × $5/M = $0.50 + $0.50 = $1.00, plus $1.25 already spent
    $state = ['budget' => ['sessionTokens' => 5000, 'sessionCost' => 1.25, 'lastTurnTokens' => 5000]];
    $out = run($g, ['gameId' => 'g1', 'state' => $state, 'response' => $response,
        'usage' => ['input' => 500000, 'output' => 100000], 'model' => 'claude-haiku-4-5-20251001']);
    assertEqual($out, accounted(['budget' => ['sessionTokens' => 605000, 'sessionCost' => 2.25, 'lastTurnTokens' => 600000]],
        $response, 605000, 600000));
});

test('accepted: Opus 4.5 pricing', function () use ($g, $response) {
    // 200,000 × $5/M + 40,000 × $25/M = $1.00 + $1.00 = $2.00
    $out = run($g, ['gameId' => 'g1', 'state' => [], 'response' => $response,
        'usage' => ['input' => 200000, 'output' => 40000], 'model' => 'claude-opus-4-5-20251101']);
    assertEqual($out, accounted(['budget' => ['sessionTokens' => 240000, 'sessionCost' => 2.0, 'lastTurnTokens' => 240000]],
        $response, 240000, 240000));
});

test('accepted: mock AI costs nothing; unknown models use the default (Sonnet) rate', function () use ($g, $response) {
    $mock = run($g, ['gameId' => 'g1', 'state' => [], 'response' => $response, 'usage' => ['input' => 150, 'output' => 350], 'model' => 'mock-ai-v1']);
    assertEqual($mock, accounted(['budget' => ['sessionTokens' => 500, 'sessionCost' => 0.0, 'lastTurnTokens' => 500]], $response, 500, 500));

    $other = run($g, ['gameId' => 'g1', 'state' => [], 'response' => $response, 'usage' => ['input' => 1000000, 'output' => 0], 'model' => 'some-new-model']);
    assertEqual($other, accounted(['budget' => ['sessionTokens' => 1000000, 'sessionCost' => 3.0, 'lastTurnTokens' => 1000000]], $response, 1000000, 1000000));
});

exit(report('turn_applied') === 0 ? 0 : 1);
