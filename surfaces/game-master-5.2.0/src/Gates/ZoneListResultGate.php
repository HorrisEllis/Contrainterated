<?php

namespace GameMaster\Gates;

use GameMaster\Pure\Http;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZoneListResultGate — answers a zone listing from the index
 * (no index, or an unreadable one, lists nothing, as before).
 *
 * Signature: zone_list_read {gameId, reads: {index}}
 * Emits:     http_response 200 {success, message, data: {gameId, count, zones}}
 */
class ZoneListResultGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('zone_list_read');
    }

    public function transform(Event $event): Event
    {
        $read = $event->data['reads']['index'] ?? [];
        $zones = ($read['status'] ?? '') === 'ok' && is_array($read['data'] ?? null) ? $read['data'] : [];
        return Http::success([
            'gameId' => $event->data['gameId'],
            'count' => count($zones),
            'zones' => $zones,
        ]);
    }
}
