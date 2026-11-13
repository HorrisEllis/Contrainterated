<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use GameMaster\Pure\Http;
use GameMaster\Pure\Ids;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * GameCreateGate — validates a new-game request and asks for
 * the world template and any existing save under that id.
 *
 * Signature: game_create_requested
 *            {worldTemplateId, playerName, gameId, overwrite, now, nonce}
 *            (raw request values; now and nonce are supplied by the boundary)
 * Emits:     io_read {template, existing} → game_create_loaded
 * Rejects:   http_response 422 (abort) — bad playerName or gameId
 */
class GameCreateGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('game_create_requested');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $playerName = $d['playerName'] ?? 'Adventurer';
        $gameId = $d['gameId'] ?? null;
        $templateId = $d['worldTemplateId'] ?? 'default';

        if (!is_string($playerName) || strlen($playerName) < 1 || strlen($playerName) > 50) {
            return Http::validationError(['playerName' => 'Player name must be 1-50 characters']);
        }
        $playerName = trim(preg_replace('/[<>"\']/', '', $playerName));

        if ($gameId !== null) {
            if (!is_string($gameId)) {
                return Http::validationError(['gameId' => 'Game ID must be a string']);
            }
            $gameId = Ids::strip($gameId);
            if (strlen($gameId) < 1 || strlen($gameId) > 100) {
                return Http::validationError(['gameId' => 'Game ID must be 1-100 alphanumeric characters']);
            }
        } else {
            $gameId = 'game-' . substr(md5((string) ($d['nonce'] ?? '')), 0, 12);
        }

        $templateId = Ids::strip(is_string($templateId) ? $templateId : 'default');

        return Io::read(
            ['template' => Io::template($templateId), 'existing' => Io::save($gameId)],
            'game_create_loaded',
            [
                'gameId' => $gameId,
                'worldTemplateId' => $templateId,
                'playerName' => $playerName,
                'overwrite' => (bool) ($d['overwrite'] ?? false),
                'now' => $d['now'] ?? '',
            ]
        );
    }
}
