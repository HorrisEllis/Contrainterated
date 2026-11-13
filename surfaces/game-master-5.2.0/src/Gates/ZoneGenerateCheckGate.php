<?php

namespace GameMaster\Gates;

use GameMaster\Pure\Http;
use GameMaster\Pure\Zones;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZoneGenerateCheckGate — plans the generation of one requested
 * zone: one zone_target, plus the join request that will store
 * the result and answer the request.
 *
 * Signature: zone_generate_loaded
 *            {gameId, zoneId, parentZoneId, direction, constraints, now,
 *             reads: {state, existing[, parent]}}
 * Emits:     [zone_target, io_collect → zones_settled (respondWith zone_generate_stored)]
 * Rejects:   http_response 404 (abort) — no such game
 *            http_response 409 (abort) — zone already exists
 */
class ZoneGenerateCheckGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('zone_generate_loaded');
    }

    public function transform(Event $event): Event|array
    {
        $d = $event->data;
        $reads = $d['reads'] ?? [];

        if (($reads['state']['status'] ?? '') !== 'ok' || !is_array($reads['state']['data'] ?? null)) {
            return Http::notFound("Game '{$d['gameId']}' not found");
        }
        if (($reads['existing']['status'] ?? 'missing') !== 'missing') {
            return Http::error("Zone '{$d['zoneId']}' already exists", 409);
        }

        $parent = ($reads['parent']['status'] ?? '') === 'ok' ? $reads['parent']['data'] : null;

        return [
            Zones::target(
                $d['gameId'], $d['zoneId'], $d['parentZoneId'], $d['direction'], $d['constraints'],
                Zones::worldContext($reads['state']['data']), Zones::parentSummary($parent), $d['now']
            ),
            Zones::collect($d['gameId'], 'zone_generate_stored', null, [$d['zoneId']]),
        ];
    }
}
