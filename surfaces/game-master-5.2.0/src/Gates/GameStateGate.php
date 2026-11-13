<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use GameMaster\Pure\Http;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * GameStateGate — answers a game-state request from the save.
 *
 * Signature: game_load_read {gameId, full, reads: {save: {status, data}}}
 * Emits:     http_response 200 {success, data: {gameId, narrative, currentZone,
 *            turnCount, player, suggestedActions[, gameState]}}
 * Rejects:   http_response 404 (abort) — save missing, unreadable or corrupt
 */
class GameStateGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('game_load_read');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $gameId = $d['gameId'];
        $save = $d['reads']['save'] ?? [];

        switch ($save['status'] ?? 'missing') {
            case 'ok':
                break;
            case 'unreadable':
                return Http::notFound('Failed to read game save file');
            case 'corrupt':
                return Http::notFound('Game save file is corrupted');
            default:
                return Http::notFound("Game '{$gameId}' not found");
        }

        $state = is_array($save['data']) ? $save['data'] : [];

        $actions = ['Look around'];
        foreach (array_slice($state['entities'] ?? [], 0, 2) as $entity) {
            if ($entity['present'] ?? true) {
                $actions[] = 'Examine ' . strtolower($entity['name'] ?? 'object');
            }
        }
        $actions[] = 'Check inventory';

        $data = [
            'gameId' => $gameId,
            'narrative' => $state['zoneDescription'] ?? 'You find yourself in an unfamiliar place...',
            'currentZone' => $state['currentZone'] ?? 'unknown',
            'turnCount' => $state['turnCount'] ?? 0,
            'player' => $state['player'] ?? [],
            'suggestedActions' => $actions,
        ];
        if ($d['full']) {
            $data['gameState'] = $state;
        }

        return Io::http(200, ['success' => true, 'data' => $data]);
    }
}
