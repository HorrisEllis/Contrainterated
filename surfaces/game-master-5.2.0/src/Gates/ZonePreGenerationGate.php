<?php

namespace GameMaster\Gates;

use GameMaster\Pure\Http;
use GameMaster\Pure\Ids;
use GameMaster\Pure\Zones;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZonePreGenerationGate — The Cartographer.
 *
 * Finds the exits of the zone being left that lead to zones not
 * generated yet (not in the zone index) and plans their
 * generation: one zone_target per target, up to the configured
 * limit, plus the join request that stores them and answers.
 *
 * Constructor-fixed configuration: $maxPreGenerate.
 *
 * Signature: zone_pregenerate_loaded {gameId, fromZoneId, now, reads: {state, from, index}}
 * Emits:     [zone_target × N (0 ≤ N ≤ max), io_collect → zones_settled
 *             (respondWith zone_pregenerate_stored)]
 * Rejects:   http_response 404 (abort) — no such game, or the from-zone is unknown
 */
class ZonePreGenerationGate extends GameGate
{
    public function __construct(private readonly int $maxPreGenerate = 3)
    {
        parent::__construct('zone_pregenerate_loaded');
    }

    public function transform(Event $event): Event|array
    {
        $d = $event->data;
        $reads = $d['reads'] ?? [];
        $gameId = $d['gameId'];
        $fromId = $d['fromZoneId'];

        if (($reads['state']['status'] ?? '') !== 'ok' || !is_array($reads['state']['data'] ?? null)) {
            return Http::notFound("Game '{$gameId}' not found");
        }
        $state = $reads['state']['data'];

        // The zone file first, then the summary kept in the save.
        $from = ($reads['from']['status'] ?? '') === 'ok' ? $reads['from']['data'] : ($state['zones'][$fromId] ?? null);
        if (!is_array($from)) {
            return Http::notFound("Zone '{$fromId}' not found");
        }

        $index = ($reads['index']['status'] ?? '') === 'ok' && is_array($reads['index']['data']) ? $reads['index']['data'] : [];

        $targets = [];
        foreach ($from['exits'] ?? [] as $exit) {
            $target = $exit['targetZoneId'] ?? null;
            if (!is_string($target) || $target === '') {
                continue;
            }
            $target = Ids::underscore($target);
            if (!Ids::isValid($target) || $target === '_index' || isset($index[$target]) || isset($targets[$target])) {
                continue;
            }
            $targets[$target] = $exit['direction'] ?? 'unknown';
        }
        $targets = array_slice($targets, 0, $this->maxPreGenerate, true);

        $world = Zones::worldContext($state);
        $parent = Zones::parentSummary($from);
        $out = [];
        foreach ($targets as $zoneId => $direction) {
            $out[] = Zones::target($gameId, (string) $zoneId, $fromId, $direction, [], $world, $parent, $d['now']);
        }
        $out[] = Zones::collect($gameId, 'zone_pregenerate_stored', $fromId, array_map('strval', array_keys($targets)));

        return $out;
    }
}
