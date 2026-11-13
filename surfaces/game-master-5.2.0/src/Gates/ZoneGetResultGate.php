<?php

namespace GameMaster\Gates;

use GameMaster\Pure\Http;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZoneGetResultGate — answers a zone lookup.
 *
 * Signature: zone_get_read {zoneId, reads: {zone}}
 * Emits:     http_response 200 {success, message, data: zone}
 * Rejects:   http_response 404 (abort) — zone not stored or unreadable
 */
class ZoneGetResultGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('zone_get_read');
    }

    public function transform(Event $event): Event
    {
        $read = $event->data['reads']['zone'] ?? [];
        if (($read['status'] ?? '') !== 'ok' || !is_array($read['data'] ?? null)) {
            return Http::notFound("Zone '{$event->data['zoneId']}' not found");
        }
        return Http::success($read['data']);
    }
}
