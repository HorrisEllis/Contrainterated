<?php

namespace GameMaster\Http\Handlers;

use GameMaster\Http\Request;
use GameMaster\Http\Response;
use Siso\Core\Event;

/**
 * GameActionHandler — POST /api/game/{gameId}/action {action}
 * (and POST /api/action {gameId, action})
 *   → action_submitted (ActionParseGate … GameResultGate / GameErrorGate)
 */
class GameActionHandler extends StreamEndpoint
{
    public function handle(Request $request, string $gameId): Response
    {
        return $this->respond(new Event('action_submitted', [
            'gameId' => $gameId,
            'action' => $request->input('action'),
            'now' => self::now(),
        ]), 'GameActionHandler::handle');
    }

    /** Test helper. */
    public function processAction(string $gameId, string $action): array
    {
        return self::decode($this->handle(
            Request::fromArray(['method' => 'POST', 'body' => ['action' => $action]]),
            $gameId
        ));
    }
}
