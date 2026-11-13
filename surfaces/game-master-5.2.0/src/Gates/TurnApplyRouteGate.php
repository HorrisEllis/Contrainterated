<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * TurnApplyRouteGate — classifies a parsed turn by world, so
 * that each world's state rules have their own gate.
 *
 * Signature: turn_apply_loaded {gameId, action, now, parsed, usage, model, reads: {save}}
 * Emits:     generic_turn_parsed {gameId, action, now, parsed, usage, model, state}
 *              (any world but Portland)
 *            io_read {template: portland_scavenger} → portland_turn_parsed,
 *              carry the same fields (world portland_scavenger)
 * Rejects:   turn_failed (abort) — the save vanished between reading and applying
 */
class TurnApplyRouteGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('turn_apply_loaded');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $save = $d['reads']['save'] ?? [];
        if (($save['status'] ?? '') !== 'ok' || !is_array($save['data'] ?? null)) {
            return new Event('turn_failed', [
                'code' => 'GAME_NOT_FOUND',
                'message' => "Game not found: {$d['gameId']}",
                'hint' => null,
            ]);
        }

        $turn = [
            'gameId' => $d['gameId'],
            'action' => $d['action'],
            'now' => $d['now'],
            'parsed' => $d['parsed'],
            'usage' => $d['usage'],
            'model' => $d['model'],
            'state' => $save['data'],
        ];

        // Portland's rules check the AI's claims against the template
        // (which landmarks exist), so its gate receives the template too.
        return ($save['data']['worldTemplateId'] ?? '') === TurnRouteGate::PORTLAND
            ? Io::read(['template' => Io::template(TurnRouteGate::PORTLAND)], 'portland_turn_parsed', $turn)
            : new Event('generic_turn_parsed', $turn);
    }
}
