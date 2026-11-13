<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ContextBuildGate — The Assembler.
 *
 * Builds the AI prompts for a turn in any world except
 * Portland, including the Phase 5 inventory and entity
 * constraints, and asks the AI for the turn.
 *
 * Signature: turn_template_loaded {gameId, action, now, state, reads: {template}}
 * Emits:     ai_call {system, user} → turn_ai_replied, carry {gameId, action, now}
 */
class ContextBuildGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('turn_template_loaded');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $read = $d['reads']['template'] ?? [];
        $template = ($read['status'] ?? '') === 'ok' && is_array($read['data'] ?? null)
            ? $read['data']
            : self::defaultTemplate();
        $state = $d['state'];

        return Io::ai(
            self::systemPrompt($template, $state),
            self::userPrompt($d['action'], $state),
            'turn_ai_replied',
            ['gameId' => $d['gameId'], 'action' => $d['action'], 'now' => $d['now']]
        );
    }

    public static function defaultTemplate(): array
    {
        return [
            'theme' => 'adventure',
            'tone' => 'mysterious',
            'worldRules' => ['Standard adventure rules apply.'],
        ];
    }

    public static function systemPrompt(array $template, array $gameState): string
    {
        $zone = $gameState['currentZone'] ?? 'unknown';
        $theme = $template['theme'] ?? 'adventure';
        $tone = $template['tone'] ?? 'mysterious';
        $zoneDesc = $gameState['zoneDescription'] ?? '';
        $entities = self::formatEntities($gameState);
        $inv = $gameState['player']['inventory'] ?? [];
        $inventory = empty($inv) ? 'empty' : implode(', ', $inv);
        $inventoryList = empty($inv) ? 'none' : implode(', ', $inv);
        $ids = array_column($gameState['entities'] ?? [], 'id');
        $entityIds = empty($ids) ? 'none' : implode(', ', $ids);
        $day = $gameState['worldState']['day'] ?? 1;
        $rules = $template['worldRules'] ?? [];
        $rules = empty($rules) ? 'Standard adventure rules' : implode("\n", $rules);

        return <<<PROMPT
You are a Game Master for a text adventure game.

THEME: {$theme}
TONE: {$tone}

CURRENT ZONE: {$zone}
{$zoneDesc}

ENTITIES IN ZONE:
{$entities}

PLAYER STATE:
- Inventory: {$inventory}
- Day: {$day}

WORLD RULES:
{$rules}

ITEM AND ENTITY CONSTRAINTS — THESE ARE ABSOLUTE RULES:
1. The player's inventory is the canonical truth. Current contents: [{$inventoryList}]
   - You may only emit "remove:X" for items that appear in that list. Never remove something the player does not have.
   - You may only emit "add:X" for items that are physically present in the scene and acquired through a legitimate action (pick up, given by NPC, etc.).
   - If the player tries to use, give, or drop an item not in their inventory, narrate that they don't have it. Do NOT add it to resolve the action.

2. The entities in this zone are the canonical truth. Current entity IDs: [{$entityIds}]
   - You may only emit entityChanges for entity IDs that appear in that list.
   - Do not invent new entities mid-scene. If the player interacts with something not in the list, narrate that it isn't there.
   - Exception: NPCs may hand the player items that make narrative sense AND are plausible given the world theme. In that case add the item to inventoryChanges and add/update the NPC entity to reflect the transfer.

3. Never silently satisfy an impossible request. If the player asks for something that doesn't exist or that they don't have, the world pushes back — that is good gameplay.

RESPONSE FORMAT (JSON):
{
  "narrative": "2-4 sentences describing what happens",
  "stateUpdates": {
    "entityChanges": [{"entityId": "...", "properties": {...}}],
    "inventoryChanges": ["add:item" or "remove:item"],
    "statChanges": {"day": 1},
    "flagChanges": {}
  },
  "suggestedActions": ["action 1", "action 2", "action 3", "action 4"],
  "newPromises": [],
  "profileInsight": "observation about player behavior (optional)"
}

Be atmospheric and specific. Maintain consistency with established facts.
PROMPT;
    }

    public static function userPrompt(string $normalizedAction, array $gameState): string
    {
        $recent = array_slice($gameState['profile']['actionHistory'] ?? [], -5);
        $history = '';
        if (!empty($recent)) {
            $history = "\n\nRECENT ACTIONS:\n";
            foreach ($recent as $h) {
                $history .= "- Turn {$h['turn']}: {$h['action']}\n";
            }
        }
        return "PLAYER ACTION: {$normalizedAction}{$history}";
    }

    private static function formatEntities(array $gameState): string
    {
        $lines = [];
        foreach ($gameState['entities'] ?? [] as $entity) {
            if ($entity['present'] ?? true) {
                $props = json_encode($entity['properties'] ?? []);
                $name = $entity['name'] ?? $entity['id'] ?? 'Unnamed';
                $id = $entity['id'] ?? '?';
                $lines[] = "- {$name} (id: {$id}): {$props}";
            }
        }
        return implode("\n", $lines) ?: 'None visible';
    }
}
