<?php

namespace GameMaster\Gates;

use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * StateUpdateGate — The Scribe.
 *
 * Applies a parsed turn to the game state (any world except
 * Portland): entities, inventory, stats, flags, turn count and
 * the player profile.
 *
 * Phase 5 inventory guard:
 *   - remove:X is discarded if X is not in the inventory
 *   - add:X is discarded unless X names a known entity (by name,
 *     case-insensitive, or by id) or is the id of an entity
 *     changed this turn; duplicates are not added
 *
 * Signature: generic_turn_parsed {gameId, action, now, parsed, usage, model, state}
 * Emits:     turn_applied {gameId, state, response, usage, model}
 *            response {narrative, suggestedActions, player, turnCount, currentZone}
 */
class StateUpdateGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('generic_turn_parsed');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $parsed = $d['parsed'];
        $updates = $parsed['stateUpdates'] ?? [];
        $entityChanges = $updates['entityChanges'] ?? [];
        $gameState = $d['state'];

        $gameState['player'] ??= ['inventory' => [], 'stats' => []];
        $gameState['player']['inventory'] ??= [];
        $gameState['worldState'] ??= ['day' => 1, 'globalFlags' => []];
        $gameState['profile'] ??= ['actionHistory' => [], 'observations' => []];
        $gameState['entities'] ??= [];

        foreach ($entityChanges as $change) {
            $gameState = self::applyEntityChange($gameState, $change);
        }
        foreach ($updates['inventoryChanges'] ?? [] as $change) {
            $gameState = self::applyInventoryChange($gameState, $change, $entityChanges);
        }
        foreach ($updates['statChanges'] ?? [] as $stat => $value) {
            $gameState['player']['stats'][$stat] = $value;
            $gameState['worldState'][$stat] = $value;
        }
        foreach ($updates['flagChanges'] ?? [] as $flag => $value) {
            $gameState['worldState']['globalFlags'][$flag] = $value;
        }

        $gameState['turnCount'] = ($gameState['turnCount'] ?? 0) + 1;
        $gameState['lastUpdated'] = $d['now'];
        $gameState['profile']['actionHistory'][] = [
            'turn' => $gameState['turnCount'],
            'action' => $d['action'],
        ];
        if (!empty($parsed['profileInsight'])) {
            $gameState['profile']['observations'][] = $parsed['profileInsight'];
        }

        return new Event('turn_applied', [
            'gameId' => $d['gameId'],
            'state' => $gameState,
            'response' => [
                'narrative' => $parsed['narrative'],
                'suggestedActions' => $parsed['suggestedActions'] ?? [],
                'player' => $gameState['player'],
                'turnCount' => $gameState['turnCount'],
                'currentZone' => $gameState['currentZone'] ?? '',
            ],
            'usage' => $d['usage'],
            'model' => $d['model'],
        ]);
    }

    private static function applyEntityChange(array $gameState, array $change): array
    {
        $entityId = $change['entityId'] ?? null;
        if (!$entityId) {
            return $gameState;
        }
        foreach ($gameState['entities'] as $i => $entity) {
            if (($entity['id'] ?? null) === $entityId) {
                foreach ($change['properties'] ?? [] as $prop => $value) {
                    $gameState['entities'][$i]['properties'][$prop] = $value;
                }
                break;
            }
        }
        return $gameState;
    }

    private static function applyInventoryChange(array $gameState, string $change, array $entityChanges): array
    {
        if (str_starts_with($change, 'add:')) {
            $item = trim(substr($change, 4));
            if (in_array($item, $gameState['player']['inventory'])) {
                return $gameState;
            }
            $knownNames = array_map(
                fn($e) => strtolower($e['name'] ?? $e['id'] ?? ''),
                $gameState['entities']
            );
            $knownIds = array_column($gameState['entities'], 'id');
            $transferredIds = array_column($entityChanges, 'entityId');

            if (in_array(strtolower($item), $knownNames)
                || in_array($item, $knownIds)
                || in_array($item, $transferredIds)) {
                $gameState['player']['inventory'][] = $item;
            }
            // Otherwise discarded: the narrative plays, the state does not change.
        } elseif (str_starts_with($change, 'remove:')) {
            $item = trim(substr($change, 7));
            if (in_array($item, $gameState['player']['inventory'])) {
                $gameState['player']['inventory'] = array_values(
                    array_filter($gameState['player']['inventory'], fn($i) => $i !== $item)
                );
            }
        }
        return $gameState;
    }
}
