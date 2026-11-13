<?php

namespace GameMaster\Gates;

use GameMaster\Pure\Http;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZoneDeletedGate — answers a zone deletion. The file is already
 * gone; an index that could not be rewritten is not an error to
 * the caller (as before).
 *
 * Signature: zone_deleted {zoneId, writes: {[index: {ok}]}, at}
 * Emits:     http_response 200 {success, message, data: {deleted: zoneId}}
 */
class ZoneDeletedGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('zone_deleted');
    }

    public function transform(Event $event): Event
    {
        return Http::success(['deleted' => $event->data['zoneId']]);
    }
}
