<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use GameMaster\Pure\Http;
use GameMaster\Pure\Ids;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZoneGenerateRequestGate — validates a request to generate one
 * zone and asks for what the check needs: the game's save, any
 * zone already stored under that id, and the parent zone.
 *
 * Signature: zone_generate_requested
 *            {gameId, zoneId, parentZoneId, direction, constraints, now}   (raw values)
 * Emits:     io_read {state, existing[, parent]} → zone_generate_loaded
 *            carry {gameId, zoneId, parentZoneId, direction, constraints, now}
 * Rejects:   http_response 400 (abort) — invalid game id
 *            http_response 422 (abort) — no zone id, over 100 characters, or "_index"
 */
class ZoneGenerateRequestGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('zone_generate_requested');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $gameId = $d['gameId'] ?? null;
        if (!Ids::isValid($gameId)) {
            return Http::error('Invalid game ID', 400);
        }
        $zoneId = $d['zoneId'] ?? null;
        if (!is_string($zoneId) || $zoneId === '') {
            return Http::validationError(['zoneId' => 'Zone ID is required']);
        }
        $zoneId = Ids::underscore($zoneId);
        if (!Ids::isValid($zoneId) || $zoneId === '_index') {
            return Http::validationError(['zoneId' => 'Zone ID must be at most 100 characters and not "_index"']);
        }

        $parent = $d['parentZoneId'] ?? null;
        $parent = is_string($parent) && $parent !== '' ? Ids::underscore($parent) : null;
        $direction = $d['direction'] ?? null;
        $constraints = $d['constraints'] ?? [];

        $reads = ['state' => Io::save($gameId), 'existing' => Io::zone($gameId, $zoneId)];
        if ($parent !== null) {
            $reads['parent'] = Io::zone($gameId, $parent);
        }

        return Io::read($reads, 'zone_generate_loaded', [
            'gameId' => $gameId,
            'zoneId' => $zoneId,
            'parentZoneId' => $parent,
            'direction' => is_string($direction) ? $direction : null,
            'constraints' => is_array($constraints) ? $constraints : [],
            'now' => $d['now'] ?? '',
        ]);
    }
}
