<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use GameMaster\Pure\Http;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZoneIndexRemoveGate — once a zone file is deleted, removes the
 * zone from the index (when there was a readable index).
 *
 * Signature: zone_file_deleted {gameId, zoneId, index, writes: {zone: {ok}}, at}
 * Emits:     io_write {index} → zone_deleted, carry {zoneId}
 *            (no writes when there was no readable index)
 * Rejects:   http_response 500 (abort) — the zone file could not be deleted
 */
class ZoneIndexRemoveGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('zone_file_deleted');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        if (!($d['writes']['zone']['ok'] ?? false)) {
            return Http::serverError("Failed to delete zone '{$d['zoneId']}'");
        }
        $writes = $d['index'] === null ? [] : ['index' => Io::put(Io::zoneIndex($d['gameId']), $d['index'])];
        return Io::write($writes, 'zone_deleted', ['zoneId' => $d['zoneId']]);
    }
}
