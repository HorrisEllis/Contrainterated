<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use GameMaster\Pure\Http;
use GameMaster\Pure\Ids;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZoneListGate — asks for a game's zone index.
 *
 * Signature: zone_list_requested {gameId}   (raw route value)
 * Emits:     io_read {index} → zone_list_read, carry {gameId}
 * Rejects:   http_response 400 (abort) — invalid game id
 */
class ZoneListGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('zone_list_requested');
    }

    public function transform(Event $event): Event
    {
        $gameId = $event->data['gameId'] ?? null;
        if (!Ids::isValid($gameId)) {
            return Http::error('Invalid game ID', 400);
        }
        return Io::read(['index' => Io::zoneIndex($gameId)], 'zone_list_read', ['gameId' => $gameId]);
    }
}
