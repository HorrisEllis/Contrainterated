<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use GameMaster\Pure\Http;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * GameInitGate — The Genesis.
 *
 * Builds the initial game state from the template and asks
 * for it to be saved.
 *
 * Signature: game_template_ready
 *            {gameId, worldTemplateId, playerName, overwrite, now, template, exists}
 * Emits:     io_write {save} → game_created
 *            carry {gameId, worldTemplateId, narrative, currentZone, player, suggestedActions}
 * Rejects:   http_response 409 (abort) — a save exists and overwrite is off
 */
class GameInitGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('game_template_ready');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $gameId = $d['gameId'];

        if ($d['exists'] && !$d['overwrite']) {
            return Http::error("Game '{$gameId}' already exists", 409);
        }

        $template = $d['template'];
        $state = self::initialState($gameId, $template, $d['playerName'], $d['now']);

        return Io::write(
            ['save' => Io::put(Io::save($gameId), $state)],
            'game_created',
            [
                'gameId' => $gameId,
                'worldTemplateId' => $d['worldTemplateId'],
                'narrative' => $state['zoneDescription'],
                'currentZone' => $state['currentZone'],
                'player' => $state['player'],
                'suggestedActions' => $template['startingSuggestions'] ?? [
                    'Look around',
                    'Check inventory',
                    'Examine your surroundings',
                ],
            ]
        );
    }

    private static function initialState(string $gameId, array $template, string $playerName, string $now): array
    {
        $startingZone = $template['startingZone'] ?? 'entrance';
        $zoneDescription = $template['startingDescription']
            ?? $template['zones'][$startingZone]['description']
            ?? 'You find yourself in a mysterious place...';
        $playerDefaults = $template['playerDefaults'] ?? [];

        if (isset($template['zones'][$startingZone]['entities'])) {
            $entities = $template['zones'][$startingZone]['entities'];
        } else {
            $entities = $template['startingEntities'] ?? [];
        }

        return [
            'gameId' => $gameId,
            'worldTemplateId' => $template['id'] ?? 'unknown',
            'created' => $now,
            'lastUpdated' => $now,
            'turnCount' => 0,
            'currentZone' => $startingZone,
            'zoneDescription' => $zoneDescription,
            'player' => [
                'name' => $playerName,
                'inventory' => $playerDefaults['inventory'] ?? [],
                'stats' => $playerDefaults['stats'] ?? ['health' => 100],
            ],
            'entities' => $entities,
            'worldState' => [
                'day' => 1,
                'time' => 'morning',
                'globalFlags' => [],
            ],
            'profile' => [
                'actionHistory' => [],
                'observations' => [],
                'patterns' => [],
            ],
            'worldTemplate' => [
                'theme' => $template['theme'] ?? 'fantasy',
                'tone' => $template['tone'] ?? 'mysterious',
                'worldRules' => $template['worldRules'] ?? [],
            ],
        ];
    }
}
