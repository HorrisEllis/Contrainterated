<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use GameMaster\Pure\Http;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * TurnRouteGate — classifies a turn by world and asks for the
 * world's template, naming the context builder that should
 * receive it.
 *
 * This replaces the old registration-order dispatch ("Portland
 * gate first, then generic"): the world is read from the event,
 * and the reply type carries the decision.
 *
 * Signature: turn_state_loaded {gameId, action, now, reads: {save}}
 * Emits:     io_read {template} → portland_turn_loaded (world portland_scavenger)
 *                                 turn_template_loaded (any other world)
 *            carry {gameId, action, now, state}
 * Rejects:   http_response 422 (abort) — no readable save: "Game not found"
 */
class TurnRouteGate extends GameGate
{
    public const PORTLAND = 'portland_scavenger';

    public function __construct()
    {
        parent::__construct('turn_state_loaded');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $save = $d['reads']['save'] ?? [];
        if (($save['status'] ?? '') !== 'ok' || !is_array($save['data'] ?? null)) {
            $msg = "Game not found: {$d['gameId']}";
            return Http::turnFailure($msg, $msg);
        }

        $state = $save['data'];
        $world = $state['worldTemplateId'] ?? 'default';
        $carry = ['gameId' => $d['gameId'], 'action' => $d['action'], 'now' => $d['now'], 'state' => $state];

        return $world === self::PORTLAND
            ? Io::read(['template' => Io::template(self::PORTLAND)], 'portland_turn_loaded', $carry)
            : Io::read(['template' => Io::template((string) $world)], 'turn_template_loaded', $carry);
    }
}
