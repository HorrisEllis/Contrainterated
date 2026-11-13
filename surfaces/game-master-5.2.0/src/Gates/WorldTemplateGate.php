<?php

namespace GameMaster\Gates;

use GameMaster\Pure\Http;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * WorldTemplateGate — The Worldbuilder.
 *
 * Settles which world template a new game uses (the requested
 * one, or the built-in default when it cannot be read) and
 * validates it.
 *
 * Signature: game_create_loaded
 *            {gameId, worldTemplateId, playerName, overwrite, now,
 *             reads: {template, existing}}
 * Emits:     game_template_ready
 *            {gameId, worldTemplateId, playerName, overwrite, now, template, exists}
 * Rejects:   http_response 400 (abort) — template fails validation
 */
class WorldTemplateGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('game_create_loaded');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $read = $d['reads']['template'] ?? [];
        $template = ($read['status'] ?? '') === 'ok' && is_array($read['data'] ?? null)
            ? $read['data']
            : self::builtinDefault();

        $errors = self::validate($template);
        if (!empty($errors)) {
            return Http::error('World template validation failed: ' . implode(', ', $errors), 400);
        }

        return new Event('game_template_ready', [
            'gameId' => $d['gameId'],
            'worldTemplateId' => $d['worldTemplateId'],
            'playerName' => $d['playerName'],
            'overwrite' => $d['overwrite'],
            'now' => $d['now'],
            'template' => $template,
            'exists' => ($d['reads']['existing']['status'] ?? 'missing') !== 'missing',
        ]);
    }

    private static function validate(array $template): array
    {
        $errors = [];
        foreach (['theme', 'tone'] as $field) {
            if (empty($template[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        if (isset($template['startingZone'], $template['zones'])
            && !isset($template['zones'][$template['startingZone']])) {
            $errors[] = "Starting zone '{$template['startingZone']}' not found in zones";
        }
        return $errors;
    }

    /** The template used when the requested one cannot be read. */
    public static function builtinDefault(): array
    {
        return [
            'id' => 'default',
            'name' => 'Mysterious World',
            'theme' => 'fantasy',
            'tone' => 'mysterious',
            'worldRules' => [
                'The world is filled with magic and mystery',
                'Choices have consequences',
                'Hidden secrets await discovery',
            ],
            'startingZone' => 'entrance',
            'startingDescription' => 'You find yourself at the entrance to a mysterious place. The air is thick with anticipation.',
            'playerDefaults' => [
                'stats' => ['health' => 100, 'energy' => 100],
                'inventory' => [],
            ],
        ];
    }
}
