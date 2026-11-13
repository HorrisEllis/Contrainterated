<?php

namespace GameMaster\Gates;

use GameMaster\Pure\Http;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * GameErrorGate — The Diplomat.
 *
 * Turns a failed turn into a player-facing, in-world answer.
 *
 * Signature: turn_failed {code, message, hint}
 * Emits:     http_response 422 {success: false, data: {narrative, suggestedActions,
 *            player, turnCount, currentZone, lastSaved}, error, meta}
 */
class GameErrorGate extends GameGate
{
    private const ERROR_NARRATIVES = [
        'AI_TIMEOUT' => 'A strange interference disrupts your thoughts. Try again in a moment.',
        'AI_PARSE_ERROR' => 'Your senses blur momentarily. What were you doing?',
        'INVALID_ACTION' => "That doesn't seem like something you can do right now.",
        'GAME_NOT_FOUND' => 'You feel disoriented, as if waking from a dream within a dream.',
        'SAVE_FAILED' => 'Something prevents you from recording your progress.',
        'VALIDATION_ERROR' => 'You hesitate, unsure how to proceed.',
    ];

    public function __construct()
    {
        parent::__construct('turn_failed');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $message = (string) ($d['message'] ?? '');
        $narrative = self::ERROR_NARRATIVES[$d['code'] ?? ''] ?? "Something unexpected happens. ({$message})";

        return Http::turnFailure($narrative, $message, empty($d['hint']) ? [] : [$d['hint']]);
    }
}
