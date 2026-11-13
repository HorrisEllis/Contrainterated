<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use GameMaster\Pure\Http;
use GameMaster\Pure\Ids;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ActionParseGate — The Interpreter.
 *
 * Validates and normalises the player's input (control
 * characters removed, spaces collapsed) and asks for the game's
 * save. The action text is all the turn needs: the AI interprets
 * it. (The old verb/target classification fed only the old mock
 * AI and was dropped as unread payload.)
 *
 * Signature: action_submitted {gameId, action, now}   (raw request values)
 * Emits:     io_read {save} → turn_state_loaded
 *            carry {gameId, action: normalised text, now}
 * Rejects:   http_response 422 (abort) — missing/invalid action or missing game id
 *            http_response 400 (abort) — game id with characters outside [A-Za-z0-9_-]
 */
class ActionParseGate extends GameGate
{
    public const MAX_LENGTH = 500;


    public function __construct()
    {
        parent::__construct('action_submitted');
    }

    public function transform(Event $event): Event
    {
        $gameId = $event->data['gameId'] ?? null;
        $action = $event->data['action'] ?? null;

        if ($gameId === null || $gameId === '') {
            return Http::validationError(['gameId' => 'Game ID is required']);
        }
        if (empty($action)) {
            return Http::validationError(['action' => 'Action is required']);
        }
        if (!is_string($action)) {
            return Http::validationError(['action' => 'Action must be a string']);
        }
        $action = trim($action);
        if (strlen($action) > self::MAX_LENGTH) {
            return Http::validationError(['action' => 'Action is too long (max 500 characters)']);
        }
        if (strlen($action) < 1) {
            return Http::validationError(['action' => 'Action cannot be empty']);
        }
        if (!Ids::isValid($gameId)) {
            return Http::error('Invalid game ID', 400);
        }

        $normalized = self::sanitize($action);

        return Io::read(['save' => Io::save($gameId)], 'turn_state_loaded', [
            'gameId' => $gameId,
            'action' => $normalized,
            'now' => $event->data['now'] ?? '',
        ]);
    }


    private static function sanitize(string $action): string
    {
        $action = preg_replace('/[\x00-\x1F\x7F]/', '', $action);
        $action = preg_replace('/\s+/', ' ', $action);
        return trim($action);
    }
}
