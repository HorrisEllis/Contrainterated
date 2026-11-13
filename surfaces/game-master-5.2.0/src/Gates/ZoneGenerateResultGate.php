<?php

namespace GameMaster\Gates;

use GameMaster\Pure\Http;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZoneGenerateResultGate — answers a single-zone generation request.
 *
 * Signature: zone_generate_stored {gameId, fromZoneId, requested: [zoneId], zones, failed,
 *            writes: {zone:<id>: {ok}, …}, at}
 * Emits:     http_response 201 {success, message, data: zone}
 * Rejects:   http_response 500 (abort) — generation failed or the zone was not written
 */
class ZoneGenerateResultGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('zone_generate_stored');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $zoneId = $d['requested'][0] ?? null;

        foreach ($d['zones'] ?? [] as $zone) {
            if ($zone['id'] === $zoneId && ($d['writes']['zone:' . $zoneId]['ok'] ?? false)) {
                return Http::success($zone, 'Zone generated successfully', 201);
            }
        }
        return Http::serverError('Zone generation failed');
    }
}
