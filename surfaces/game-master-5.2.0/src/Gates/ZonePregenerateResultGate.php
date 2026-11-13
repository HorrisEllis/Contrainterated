<?php

namespace GameMaster\Gates;

use GameMaster\Pure\Http;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZonePregenerateResultGate — answers a pre-generation request
 * with what was generated and what failed.
 *
 * Signature: zone_pregenerate_stored {gameId, fromZoneId, requested, zones, failed,
 *            writes: {zone:<id>: {ok}, …}, at}
 * Emits:     http_response 200 {success, message, data: {fromZoneId, requested,
 *            generated: [{zoneId, name}], errors: [{zoneId, error}]}}
 */
class ZonePregenerateResultGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('zone_pregenerate_stored');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $generated = [];
        $errors = $d['failed'] ?? [];

        foreach ($d['zones'] ?? [] as $zone) {
            if ($d['writes']['zone:' . $zone['id']]['ok'] ?? false) {
                $generated[] = ['zoneId' => $zone['id'], 'name' => $zone['name']];
            } else {
                $errors[] = ['zoneId' => $zone['id'], 'error' => "Failed to save zone '{$zone['id']}'"];
            }
        }

        return Http::success([
            'fromZoneId' => $d['fromZoneId'],
            'requested' => count($d['requested'] ?? []),
            'generated' => $generated,
            'errors' => $errors,
        ]);
    }
}
