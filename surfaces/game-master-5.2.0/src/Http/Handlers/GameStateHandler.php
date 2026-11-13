<?php

namespace GameMaster\Http\Handlers;

use GameMaster\Http\Request;
use GameMaster\Http\Response;
use Siso\Core\Event;

/**
 * GameStateHandler — GET /api/game/{gameId}[?full=true]
 *   → game_load_requested (GameLoadGate, GameStateGate)
 */
class GameStateHandler extends StreamEndpoint
{
    public function handle(Request $request, string $gameId): Response
    {
        return $this->respond(new Event('game_load_requested', [
            'gameId' => $gameId,
            'full' => $request->query('full'),
        ]), 'GameStateHandler::handle');
    }

    /** Test helper. */
    public function loadGame(string $gameId, bool $full = false): array
    {
        return self::decode($this->handle(
            Request::fromArray(['method' => 'GET', 'query' => ['full' => $full ? 'true' : 'false']]),
            $gameId
        ));
    }
}
