<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZoneStoreGate — The Librarian.
 *
 * Runs once all planned zones have settled (the reply to the
 * planner's io_collect). Writes every generated zone, adds them
 * to the zone index, and records their summaries in the game
 * state, linking the parent zone's open exit to the new zone.
 * One batch of writes, so several zones generated together
 * cannot overwrite each other's index or state updates.
 * A zone whose file already exists is skipped and reported.
 *
 * Signature: zones_settled {gameId, respondWith, fromZoneId, requested,
 *            collected: [{type: zone_parsed|zone_failed, data}],
 *            reads: {state, index, existing:<id>…}}
 * Emits:     io_write {zone:<id>…, index, state} → <respondWith>
 *            (no writes when no new zone was generated)
 *            carry {gameId, fromZoneId, requested, zones: [zone], failed: [{zoneId, error}]}
 * Rejects:   the same io_write with no writes and every zone reported
 *            failed (abort) — the save is unreadable (game gone)
 */
class ZoneStoreGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('zones_settled');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $gameId = $d['gameId'];

        $zones = [];
        $failed = [];
        foreach ($d['collected'] ?? [] as $e) {
            if (($e['data']['gameId'] ?? null) !== $gameId) {
                continue;
            }
            if ($e['type'] === 'zone_parsed') {
                $zones[] = $e['data']['zone'];
            } elseif ($e['type'] === 'zone_failed') {
                $failed[] = ['zoneId' => $e['data']['zoneId'], 'error' => $e['data']['error']];
            }
        }

        $reads = $d['reads'] ?? [];
        $stateRead = $reads['state'] ?? [];
        $state = ($stateRead['status'] ?? '') === 'ok' && is_array($stateRead['data']) ? $stateRead['data'] : null;

        // Abort: the game is gone — store nothing, report every zone.
        if ($state === null) {
            foreach ($zones as $zone) {
                $failed[] = ['zoneId' => $zone['id'], 'error' => "Game '{$gameId}' not found"];
            }
            $zones = [];
        }

        // A zone file that already exists is never overwritten (it may be
        // hand-made, or missing from the index); it is reported instead.
        $new = [];
        foreach ($zones as $zone) {
            if (($reads["existing:{$zone['id']}"]['status'] ?? 'missing') !== 'missing') {
                $failed[] = ['zoneId' => $zone['id'], 'error' => "Zone '{$zone['id']}' already exists"];
            } else {
                $new[] = $zone;
            }
        }
        $zones = $new;

        $writes = [];
        if (!empty($zones)) {
            $indexRead = $reads['index'] ?? [];
            $index = ($indexRead['status'] ?? '') === 'ok' && is_array($indexRead['data']) ? $indexRead['data'] : [];
            foreach ($zones as $zone) {
                $writes['zone:' . $zone['id']] = Io::put(Io::zone($gameId, $zone['id']), $zone);
                $index[$zone['id']] = self::indexEntry($zone);
                $state = self::recordInState($state, $zone);
            }
            $writes['index'] = Io::put(Io::zoneIndex($gameId), $index);
            $writes['state'] = Io::put(Io::save($gameId), $state);
        }

        return Io::write($writes, $d['respondWith'], [
            'gameId' => $gameId,
            'fromZoneId' => $d['fromZoneId'],
            'requested' => $d['requested'],
            'zones' => $zones,
            'failed' => $failed,
        ]);
    }

    private static function indexEntry(array $zone): array
    {
        return [
            'name' => $zone['name'],
            'parentZoneId' => $zone['parentZoneId'] ?? null,
            'direction' => $zone['direction'] ?? null,
            'exits' => array_column($zone['exits'] ?? [], 'direction'),
            'generated' => $zone['generated'],
            'dangerLevel' => $zone['properties']['danger_level'] ?? 0,
        ];
    }

    private static function recordInState(array $state, array $zone): array
    {
        $state['zones'] ??= [];
        $state['zones'][$zone['id']] = [
            'name' => $zone['name'],
            'description' => $zone['description'],
            'exits' => array_map(fn($exit) => [
                'direction' => $exit['direction'],
                'targetZoneId' => $exit['targetZoneId'],
                'locked' => $exit['locked'] ?? false,
            ], $zone['exits'] ?? []),
            'visited' => false,
            'generated' => $zone['generated'],
        ];

        $parentId = $zone['parentZoneId'] ?? null;
        if ($parentId !== null && isset($state['zones'][$parentId]['exits'])) {
            foreach ($state['zones'][$parentId]['exits'] as $i => $exit) {
                if ($exit['direction'] === $zone['direction'] && $exit['targetZoneId'] === null) {
                    $state['zones'][$parentId]['exits'][$i]['targetZoneId'] = $zone['id'];
                }
            }
        }
        return $state;
    }
}
