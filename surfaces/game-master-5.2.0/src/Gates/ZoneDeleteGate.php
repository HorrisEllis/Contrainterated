<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use GameMaster\Pure\Http;
use GameMaster\Pure\Ids;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZoneDeleteGate — asks for the zone and index a deletion touches.
 *
 * Signature: zone_delete_requested {gameId, zoneId}   (raw route values)
 * Emits:     io_read {zone, index} → zone_delete_loaded, carry {gameId, zoneId}
 * Rejects:   http_response 400 (abort) — invalid game id
 *            http_response 404 (abort) — zone id cannot name a stored zone
 */
class ZoneDeleteGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('zone_delete_requested');
    }

    public function transform(Event $event): Event
    {
        $gameId = $event->data['gameId'] ?? null;
        $zoneId = $event->data['zoneId'] ?? null;
        if (!Ids::isValid($gameId)) {
            return Http::error('Invalid game ID', 400);
        }
        if (!Ids::isValid($zoneId)) {
            return Http::notFound("Zone '" . (is_string($zoneId) ? $zoneId : '') . "' not found");
        }
        return Io::read(
            ['zone' => Io::zone($gameId, $zoneId), 'index' => Io::zoneIndex($gameId)],
            'zone_delete_loaded',
            ['gameId' => $gameId, 'zoneId' => $zoneId]
        );
    }
}
