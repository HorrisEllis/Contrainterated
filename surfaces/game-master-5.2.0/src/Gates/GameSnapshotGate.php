<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * GameSnapshotGate — The Chronicler.
 *
 * Asks for the turn's new game state to be saved.
 *
 * Signature: turn_accounted {gameId, state, response, meta}
 * Emits:     io_write {save} → turn_saved, carry {response, meta}
 */
class GameSnapshotGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('turn_accounted');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        return Io::write(
            ['save' => Io::put(Io::save($d['gameId']), $d['state'])],
            'turn_saved',
            ['response' => $d['response'], 'meta' => $d['meta']]
        );
    }
}
