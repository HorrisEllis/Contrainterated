<?php

namespace GameMaster\Pure;

use GameMaster\Events\Io;
use Siso\Core\Event;

/**
 * Zones — pure helpers shared by the zone-planning gates.
 */
final class Zones
{
    /** The parts of a game state a zone prompt uses. */
    public static function worldContext(array $state): array
    {
        return [
            'worldTemplate' => $state['worldTemplate'] ?? [],
            'currentZone' => $state['currentZone'] ?? null,
            'worldState' => $state['worldState'] ?? [],
            'playerStats' => $state['player']['stats'] ?? [],
            'observations' => $state['profile']['observations'] ?? [],
        ];
    }

    /** Name and description of a parent zone, or null. */
    public static function parentSummary(?array $zone): ?array
    {
        if (!is_array($zone) || !isset($zone['name'])) {
            return null;
        }
        return ['name' => $zone['name'], 'description' => $zone['description'] ?? ''];
    }

    public static function target(
        string $gameId,
        string $zoneId,
        ?string $parentZoneId,
        ?string $direction,
        array $constraints,
        array $world,
        ?array $parent,
        string $now
    ): Event {
        return new Event('zone_target', [
            'gameId' => $gameId,
            'zoneId' => $zoneId,
            'parentZoneId' => $parentZoneId,
            'direction' => $direction,
            'constraints' => $constraints,
            'world' => $world,
            'parent' => $parent,
            'now' => $now,
        ]);
    }

    /**
     * The join request. The boundary answers it once the stream holds
     * one zone_parsed or zone_failed event for every requested zone id
     * of this game — completion is read from the events, not from what
     * else is outstanding — with those events, a fresh read of the save
     * and the zone index, and whether each requested zone file exists.
     */
    public static function collect(string $gameId, string $respondWith, ?string $fromZoneId, array $requested): Event
    {
        $reads = ['state' => Io::save($gameId), 'index' => Io::zoneIndex($gameId)];
        foreach ($requested as $zoneId) {
            $reads["existing:{$zoneId}"] = Io::zone($gameId, $zoneId);
        }
        return Io::collect(
            ['zone_parsed', 'zone_failed'],
            $reads,
            'zones_settled',
            ['gameId' => $gameId, 'respondWith' => $respondWith, 'fromZoneId' => $fromZoneId, 'requested' => $requested],
            ['gameId' => $gameId],
            'zoneId',
            $requested
        );
    }
}
