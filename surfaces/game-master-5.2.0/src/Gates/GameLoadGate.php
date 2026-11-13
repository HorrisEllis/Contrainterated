<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use GameMaster\Pure\Http;
use GameMaster\Pure\Ids;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * GameLoadGate — The Archivist.
 *
 * Validates a game-state request and asks for the save.
 *
 * Signature: game_load_requested {gameId, full}   (raw request values)
 * Emits:     io_read {save} → game_load_read, carry {gameId, full: bool}
 * Rejects:   http_response 400 (abort) — missing or invalid game id
 */
class GameLoadGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('game_load_requested');
    }

    public function transform(Event $event): Event
    {
        $gameId = $event->data['gameId'] ?? '';
        if (!is_string($gameId) || $gameId === '') {
            return Http::error('Game ID is required', 400);
        }
        $gameId = Ids::strip($gameId);
        if (strlen($gameId) < 1 || strlen($gameId) > 100) {
            return Http::error('Invalid game ID', 400);
        }

        $full = $event->data['full'] ?? null;

        return Io::read(['save' => Io::save($gameId)], 'game_load_read', [
            'gameId' => $gameId,
            'full' => $full === 'true' || $full === '1' || $full === true,
        ]);
    }
}
