<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use GameMaster\Pure\Http;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZoneDeleteApplyGate — deletes a stored zone's file. The index
 * entry is removed only once the file is gone (ZoneIndexRemoveGate),
 * so a failed delete never leaves a file the index has forgotten.
 *
 * Signature: zone_delete_loaded {gameId, zoneId, reads: {zone, index}}
 * Emits:     io_write {zone: delete} → zone_file_deleted
 *            carry {gameId, zoneId, index: the index without this zone | null when unreadable}
 * Rejects:   http_response 404 (abort) — no such zone
 */
class ZoneDeleteApplyGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('zone_delete_loaded');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $gameId = $d['gameId'];
        $zoneId = $d['zoneId'];

        if (($d['reads']['zone']['status'] ?? 'missing') === 'missing') {
            return Http::notFound("Zone '{$zoneId}' not found");
        }

        $index = $d['reads']['index'] ?? [];
        $remaining = null;
        if (($index['status'] ?? '') === 'ok' && is_array($index['data'])) {
            $remaining = $index['data'];
            unset($remaining[$zoneId]);
        }

        return Io::write(
            ['zone' => Io::delete(Io::zone($gameId, $zoneId))],
            'zone_file_deleted',
            ['gameId' => $gameId, 'zoneId' => $zoneId, 'index' => $remaining]
        );
    }
}
