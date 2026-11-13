<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use GameMaster\Pure\Http;
use GameMaster\Pure\Ids;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZoneGetGate — asks for one stored zone.
 *
 * Signature: zone_get_requested {gameId, zoneId}   (raw route values)
 * Emits:     io_read {zone} → zone_get_read, carry {zoneId}
 * Rejects:   http_response 400 (abort) — invalid game id
 *            http_response 404 (abort) — zone id cannot name a stored zone
 */
class ZoneGetGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('zone_get_requested');
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
        return Io::read(['zone' => Io::zone($gameId, $zoneId)], 'zone_get_read', ['zoneId' => $zoneId]);
    }
}
