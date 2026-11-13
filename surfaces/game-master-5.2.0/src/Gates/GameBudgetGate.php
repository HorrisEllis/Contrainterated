<?php

namespace GameMaster\Gates;

use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * GameBudgetGate — The Accountant.
 *
 * Adds a turn's AI token usage and estimated cost to the
 * session totals kept in the game state (`state.budget`), and
 * produces the `meta` block the action endpoint returns.
 *
 * The totals used to live in the gate itself and reset on every
 * request; they now persist with the save. Limits are recorded
 * nowhere and enforced nowhere, as before (see changelog).
 *
 * Signature: turn_applied {gameId, state, response, usage: {input, output}, model}
 * Emits:     turn_accounted {gameId, state, response, meta: {tokensUsed, turnTokens}}
 */
class GameBudgetGate extends GameGate
{
    /**
     * USD per 1M tokens, [input, output] — base rates from
     * https://platform.claude.com/docs/en/about-claude/pricing (checked 2026-09-27).
     */
    private const TOKEN_COSTS = [
        'claude-sonnet-4-20250514' => [3.00, 15.00],
        'claude-haiku-4-5-20251001' => [1.00, 5.00],
        'claude-opus-4-5-20251101' => [5.00, 25.00],
        'mock-ai-v1' => [0.00, 0.00],
        'default' => [3.00, 15.00],
    ];

    public function __construct()
    {
        parent::__construct('turn_applied');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $input = (int) ($d['usage']['input'] ?? 0);
        $output = (int) ($d['usage']['output'] ?? 0);
        $turnTokens = $input + $output;
        [$inRate, $outRate] = self::TOKEN_COSTS[$d['model']] ?? self::TOKEN_COSTS['default'];
        $cost = ($input / 1000000) * $inRate + ($output / 1000000) * $outRate;

        $state = $d['state'];
        $budget = $state['budget'] ?? [];
        $state['budget'] = [
            'sessionTokens' => (int) ($budget['sessionTokens'] ?? 0) + $turnTokens,
            'sessionCost' => (float) ($budget['sessionCost'] ?? 0.0) + $cost,
            'lastTurnTokens' => $turnTokens,
        ];

        return new Event('turn_accounted', [
            'gameId' => $d['gameId'],
            'state' => $state,
            'response' => $d['response'],
            'meta' => ['tokensUsed' => $state['budget']['sessionTokens'], 'turnTokens' => $turnTokens],
        ]);
    }
}
