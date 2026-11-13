<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZoneContextGate — The Architect.
 *
 * Builds the AI prompts for generating one zone, consistent
 * with the world's theme, the zone it connects from and the
 * player's recent behaviour, and asks the AI for it.
 *
 * Signature: zone_target {gameId, zoneId, parentZoneId, direction, constraints,
 *            world: {worldTemplate, currentZone, worldState, playerStats, observations},
 *            parent: {name, description} | null, now}
 * Emits:     ai_call {system, user} → zone_ai_replied
 *            carry {gameId, zoneId, parentZoneId, direction, now}
 */
class ZoneContextGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('zone_target');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        return Io::ai(
            $this->buildSystemPrompt($d['world']),
            $this->buildUserPrompt($d),
            'zone_ai_replied',
            [
                'gameId' => $d['gameId'],
                'zoneId' => $d['zoneId'],
                'parentZoneId' => $d['parentZoneId'],
                'direction' => $d['direction'],
                'now' => $d['now'],
            ]
        );
    }

    private function buildSystemPrompt(array $world): string
    {
        $theme = $world['worldTemplate']['theme'] ?? 'fantasy';
        $tone = $world['worldTemplate']['tone'] ?? 'mysterious';
        $worldRules = $world['worldTemplate']['worldRules'] ?? [];

        $rulesText = !empty($worldRules)
            ? "World Rules:\n- " . implode("\n- ", $worldRules)
            : "";

        return <<<PROMPT
You are a world-building AI for a text adventure game.
Your task is to generate a new zone/area that fits within the established world.

Theme: {$theme}
Tone: {$tone}
{$rulesText}

IMPORTANT: Respond ONLY with valid JSON in this exact format:
{
  "name": "Short descriptive name for the zone",
  "description": "2-4 sentences describing what the player sees when entering. Use second person (you). Be atmospheric and evocative.",
  "entities": [
    {
      "id": "unique_entity_id",
      "name": "Display Name",
      "description": "Brief description for examination",
      "properties": {
        "interactable": true,
        "type": "object|npc|hazard|container"
      }
    }
  ],
  "exits": [
    {
      "direction": "north|south|east|west|up|down|custom",
      "targetZoneId": "zone_id_or_null_for_ungenerated",
      "description": "What the exit looks like",
      "locked": false,
      "requirements": []
    }
  ],
  "properties": {
    "lighting": "dark|dim|normal|bright",
    "danger_level": 0-10,
    "ambient_sounds": ["sound1", "sound2"],
    "special_features": []
  }
}

Generate 2-5 interesting entities and 2-4 exits. Be creative but consistent with the theme.
PROMPT;
    }

    private function buildUserPrompt(array $d): string
    {
        $world = $d['world'];
        $parts = [];
        $parts[] = "Generate zone with ID: {$d['zoneId']}";

        if ($d['parentZoneId']) {
            if ($d['parent']) {
                $parts[] = "\nConnected from: {$d['parent']['name']}";
                $parts[] = "Parent zone description: {$d['parent']['description']}";
            }
            if ($d['direction']) {
                $parts[] = "Direction from parent: {$d['direction']}";
            }
        }

        if (!empty($world['currentZone'])) {
            $parts[] = "\nCurrent game zone: {$world['currentZone']}";
        }
        if (!empty($world['worldState'])) {
            $state = $world['worldState'];
            if (isset($state['day'])) {
                $parts[] = "Day: {$state['day']}";
            }
            if (isset($state['time'])) {
                $parts[] = "Time: {$state['time']}";
            }
        }

        if (!empty($d['constraints'])) {
            $parts[] = "\nConstraints:";
            foreach ($d['constraints'] as $key => $value) {
                if (is_array($value)) {
                    $value = implode(', ', array_map(fn($v) => is_scalar($v) ? (string) $v : json_encode($v), $value));
                } elseif (!is_scalar($value) && $value !== null) {
                    $value = json_encode($value);
                }
                $parts[] = "- {$key}: {$value}";
            }
        }

        if (!empty($world['playerStats'])) {
            $parts[] = "\nPlayer stats: " . json_encode($world['playerStats']);
        }

        if (!empty($world['observations'])) {
            $observations = array_slice($world['observations'], -3);
            $parts[] = "\nRecent player observations: " . implode('; ', $observations);
        }

        $parts[] = "\nGenerate a zone that fits naturally in this world and provides interesting exploration opportunities.";

        return implode("\n", $parts);
    }
}
