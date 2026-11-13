<?php

namespace GameMaster\Gates;

use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * PortlandStateGate — applies a parsed turn in the Portland
 * Scavenger Hunt world.
 *
 * Handles location (lat/lng), game time (timeAdvance, or the
 * AI's currentTime when given), money, inventory, landmark
 * discovery and the win condition, and appends the Street
 * View link (and the win message) to the narrative.
 *
 * The world-specific fields come from the AI and are checked
 * before they touch the save; a field of the wrong type is
 * ignored, so one malformed reply cannot corrupt the game:
 *   stateUpdates.location      array with a string name; lat/lng
 *                              numeric, streetview/description strings
 *   stateUpdates.timeAdvance   number of minutes > 0
 *   currentTime                "HH:MM"
 *   stateUpdates.moneyChange   number
 *   stateUpdates.landmarkFound a landmark id the template defines
 *   streetViewLink             http(s) URL
 *
 * Signature: portland_turn_parsed
 *            {gameId, action, now, parsed, usage, model, state, reads: {template}}
 * Emits:     turn_applied {gameId, state, response, usage, model}
 */
class PortlandStateGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('portland_turn_parsed');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $parsed = $d['parsed'];
        $stateUpdates = is_array($parsed['stateUpdates'] ?? null) ? $parsed['stateUpdates'] : [];
        $gameState = self::initializePortlandState($d['state']);

        $template = $d['reads']['template'] ?? [];
        $landmarkIds = ($template['status'] ?? '') === 'ok'
            ? array_map('strval', array_keys($template['data']['landmarks'] ?? []))
            : [];

        $location = self::validLocation($stateUpdates['location'] ?? null);
        if ($location !== null) {
            $gameState['location'] = $location;
            $gameState['currentZone'] = $location['name'];
        }

        $advance = $stateUpdates['timeAdvance'] ?? null;
        if (is_numeric($advance) && $advance > 0) {
            $gameState['worldState']['time'] = self::advanceTime($gameState['worldState']['time'], (int) $advance);
        }
        $currentTime = $parsed['currentTime'] ?? null;
        if (is_string($currentTime) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $currentTime)) {
            $gameState['worldState']['time'] = $currentTime;
        }

        $money = $stateUpdates['moneyChange'] ?? null;
        if (is_int($money) || is_float($money)) {
            $gameState['player']['stats']['money'] = ($gameState['player']['stats']['money'] ?? 0) + $money;
        }

        foreach ($stateUpdates['inventoryChanges'] ?? [] as $change) {
            $gameState = self::applyInventoryChange($gameState, $change);
        }

        $landmarkId = $stateUpdates['landmarkFound'] ?? null;
        if (is_string($landmarkId) && in_array($landmarkId, $landmarkIds, true)
            && !in_array($landmarkId, $gameState['worldState']['landmarks_visited'], true)) {
            $gameState['worldState']['landmarks_visited'][] = $landmarkId;
            $gameState['player']['stats']['landmarks_found'] = count($gameState['worldState']['landmarks_visited']);
        }

        foreach ($stateUpdates['flagChanges'] ?? [] as $flag => $value) {
            $gameState['worldState']['globalFlags'][$flag] = $value;
        }

        $gameState['turnCount'] = ($gameState['turnCount'] ?? 0) + 1;
        $gameState['lastUpdated'] = $d['now'];
        $gameState['profile']['actionHistory'][] = [
            'turn' => $gameState['turnCount'],
            'action' => $d['action'],
            'time' => $gameState['worldState']['time'],
            'location' => $gameState['location']['name'] ?? 'unknown',
        ];

        $landmarksFound = $gameState['player']['stats']['landmarks_found'] ?? 0;
        $won = $landmarksFound >= 10;
        if ($won) {
            $gameState['worldState']['globalFlags']['game_won'] = true;
        }

        $narrative = $parsed['narrative'];
        $streetView = self::url($parsed['streetViewLink'] ?? null) ?? ($location['streetview'] ?? null);
        if ($streetView !== null) {
            $narrative .= "\n\n[View this location]({$streetView})";
        }
        if ($won && $landmarksFound === 10) {
            $narrative .= "\n\n---\n\n**CONGRATULATIONS!** You've found all 10 landmarks. ";
            $narrative .= "As you say \"I am here\" for the final time, your phone buzzes. ";
            $narrative .= "You look down - wait, you have a phone now? A message reads: ";
            $narrative .= "\"Well done. Check your bank account.\" The game is complete.";
        }

        return new Event('turn_applied', [
            'gameId' => $d['gameId'],
            'state' => $gameState,
            'response' => [
                'narrative' => $narrative,
                'suggestedActions' => $parsed['suggestedActions'] ?? [],
                'player' => $gameState['player'],
                'turnCount' => $gameState['turnCount'],
                'currentZone' => $gameState['currentZone'] ?? '',
            ],
            'usage' => $d['usage'],
            'model' => $d['model'],
        ]);
    }

    /** A location the AI sent, reduced to well-typed fields, or null when unusable. */
    private static function validLocation(mixed $location): ?array
    {
        if (!is_array($location) || !is_string($location['name'] ?? null) || trim($location['name']) === '') {
            return null;
        }
        $valid = ['name' => $location['name']];
        foreach (['lat', 'lng'] as $k) {
            if (is_int($location[$k] ?? null) || is_float($location[$k] ?? null)) {
                $valid[$k] = $location[$k];
            }
        }
        if (($url = self::url($location['streetview'] ?? null)) !== null) {
            $valid['streetview'] = $url;
        }
        if (is_string($location['description'] ?? null)) {
            $valid['description'] = $location['description'];
        }
        return $valid;
    }

    private static function url(mixed $value): ?string
    {
        return is_string($value) && preg_match('#^https?://\S+$#', $value) ? $value : null;
    }

    private static function initializePortlandState(array $gameState): array
    {
        $gameState['worldState'] ??= [];
        $gameState['worldState']['time'] ??= '06:00';
        $gameState['worldState']['date'] ??= 'October 15';
        $gameState['worldState']['landmarks_visited'] ??= [];
        $gameState['worldState']['globalFlags'] ??= [];

        $gameState['player'] ??= [];
        $gameState['player']['stats'] ??= [];
        $gameState['player']['stats']['money'] ??= 0;
        $gameState['player']['stats']['landmarks_found'] = count($gameState['worldState']['landmarks_visited']);

        $gameState['location'] ??= [
            'name' => 'Pioneer Courthouse Square',
            'lat' => 45.5189,
            'lng' => -122.6793,
            'streetview' => 'https://www.google.com/maps?layer=c&cbll=45.5189,-122.6793',
        ];
        $gameState['profile'] ??= ['actionHistory' => [], 'observations' => []];

        return $gameState;
    }

    /** Advance an HH:MM clock by minutes, wrapping at midnight. */
    private static function advanceTime(string $currentTime, int $minutes): string
    {
        $parts = explode(':', $currentTime);
        $total = ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0)) + $minutes;
        $total %= 24 * 60;
        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    private static function applyInventoryChange(array $gameState, string $change): array
    {
        $gameState['player']['inventory'] ??= [];
        if (str_starts_with($change, 'add:')) {
            $item = substr($change, 4);
            if (!in_array($item, $gameState['player']['inventory'])) {
                $gameState['player']['inventory'][] = $item;
            }
        } elseif (str_starts_with($change, 'remove:')) {
            $item = substr($change, 7);
            $gameState['player']['inventory'] = array_values(
                array_filter($gameState['player']['inventory'], fn($i) => $i !== $item)
            );
        }
        return $gameState;
    }
}
