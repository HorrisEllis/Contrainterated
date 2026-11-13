<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use GameMaster\Pure\Http;
use GameMaster\Pure\Ids;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZonePregenerateRequestGate — validates a pre-generation request
 * and asks for the save, the zone being left and the zone index.
 *
 * Signature: zone_pregenerate_requested {gameId, fromZoneId, now}   (raw values)
 * Emits:     io_read {state, from, index} → zone_pregenerate_loaded
 *            carry {gameId, fromZoneId, now}
 * Rejects:   http_response 400 (abort) — invalid game id
 *            http_response 422 (abort) — no fromZoneId
 */
class ZonePregenerateRequestGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('zone_pregenerate_requested');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $gameId = $d['gameId'] ?? null;
        if (!Ids::isValid($gameId)) {
            return Http::error('Invalid game ID', 400);
        }
        $from = $d['fromZoneId'] ?? null;
        if (!is_string($from) || $from === '') {
            return Http::validationError(['fromZoneId' => 'From zone ID is required']);
        }
        $from = Ids::underscore($from);

        return Io::read(
            ['state' => Io::save($gameId), 'from' => Io::zone($gameId, $from), 'index' => Io::zoneIndex($gameId)],
            'zone_pregenerate_loaded',
            ['gameId' => $gameId, 'fromZoneId' => $from, 'now' => $d['now'] ?? '']
        );
    }
}
