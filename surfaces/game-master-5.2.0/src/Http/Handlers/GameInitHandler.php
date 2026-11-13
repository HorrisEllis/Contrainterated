<?php

namespace GameMaster\Http\Handlers;

use GameMaster\Http\Request;
use GameMaster\Http\Response;
use Siso\Core\Event;

/**
 * GameInitHandler — new games and the template list.
 *
 * POST /api/game/new   {worldTemplateId, playerName, gameId?, overwrite?}
 *   → game_create_requested (GameCreateGate … GameCreatedGate)
 * GET  /api/templates
 *   → templates_requested (TemplateListGate, TemplateCatalogGate)
 */
class GameInitHandler extends StreamEndpoint
{
    public function handle(Request $request): Response
    {
        return $this->respond(new Event('game_create_requested', [
            'worldTemplateId' => $request->input('worldTemplateId'),
            'playerName' => $request->input('playerName'),
            'gameId' => $request->input('gameId'),
            'overwrite' => $request->input('overwrite', false),
            'now' => self::now(),
            'nonce' => self::nonce(),
        ]), 'GameInitHandler::handle');
    }

    public function listTemplates(Request $request): Response
    {
        return $this->respond(new Event('templates_requested'), 'GameInitHandler::listTemplates');
    }

    /** Test helper. */
    public function createGame(string $worldTemplateId = 'default', string $playerName = 'Adventurer', ?string $gameId = null): array
    {
        return self::decode($this->handle(Request::fromArray([
            'method' => 'POST',
            'body' => ['worldTemplateId' => $worldTemplateId, 'playerName' => $playerName, 'gameId' => $gameId],
        ])));
    }
}
