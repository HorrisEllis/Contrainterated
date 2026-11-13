<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * GameResultGate — The Herald.
 *
 * Answers the action request once the turn has been saved.
 * A failed save still answers 200 with lastSaved "failed",
 * as before: the turn happened, only persistence did not.
 *
 * Signature: turn_saved {response, meta, writes: {save: {ok}}, at}
 * Emits:     http_response 200 {success, data: {narrative, suggestedActions,
 *            player, turnCount, currentZone, lastSaved}, error: null, meta}
 */
class GameResultGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('turn_saved');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $saved = ($d['writes']['save']['ok'] ?? false) ? ($d['at'] ?? null) : 'failed';

        return Io::http(200, [
            'success' => true,
            'data' => $d['response'] + ['lastSaved' => $saved],
            'error' => null,
            'meta' => $d['meta'],
        ]);
    }
}
