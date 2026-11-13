<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use GameMaster\Pure\Http;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * GameCreatedGate — answers a new-game request once the
 * initial save has been written.
 *
 * Signature: game_created
 *            {gameId, worldTemplateId, narrative, currentZone, player,
 *             suggestedActions, writes: {save: {ok}}, at}
 * Emits:     http_response 201 {success, message, data}
 * Rejects:   http_response 400 (abort) — the save could not be written
 */
class GameCreatedGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('game_created');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        if (!($d['writes']['save']['ok'] ?? false)) {
            return Http::error('Failed to save initial game state', 400);
        }

        return Io::http(201, [
            'success' => true,
            'message' => 'Game created successfully',
            'data' => [
                'gameId' => $d['gameId'],
                'worldTemplateId' => $d['worldTemplateId'],
                'narrative' => $d['narrative'],
                'currentZone' => $d['currentZone'],
                'turnCount' => 0,
                'player' => $d['player'],
                'suggestedActions' => $d['suggestedActions'],
            ],
        ]);
    }
}
