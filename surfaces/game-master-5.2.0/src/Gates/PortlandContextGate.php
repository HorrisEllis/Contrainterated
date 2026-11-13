<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * PortlandContextGate — context builder for the Portland
 * Scavenger Hunt world.
 *
 * Builds AI prompts with real Portland geography and distances,
 * game time, weather and daylight, money, found landmarks and
 * Street View links, and asks the AI for the turn.
 *
 * Signature: portland_turn_loaded {gameId, action, now, state, reads: {template}}
 * Emits:     ai_call {system, user} → turn_ai_replied, carry {gameId, action, now}
 */
class PortlandContextGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('portland_turn_loaded');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $read = $d['reads']['template'] ?? [];
        $template = ($read['status'] ?? '') === 'ok' && is_array($read['data'] ?? null) ? $read['data'] : [];
        $gameState = $d['state'];

        return Io::ai(
            $this->buildPortlandSystemPrompt($template, $gameState),
            $this->buildPortlandUserPrompt($d['action'], $gameState),
            'turn_ai_replied',
            ['gameId' => $d['gameId'], 'action' => $d['action'], 'now' => $d['now']]
        );
    }

    private function buildPortlandSystemPrompt(array $template, array $gameState): string
    {
        $location = $gameState['location'] ?? $template['zones']['pioneer_square'] ?? [];
        $locationName = $location['name'] ?? 'Pioneer Courthouse Square';
        $locationDesc = $location['description'] ?? '';
        $streetView = $location['streetview'] ?? '';

        $gameTime = $gameState['worldState']['time'] ?? '06:00';
        $gameDate = $gameState['worldState']['date'] ?? 'October 15';
        $money = $gameState['player']['stats']['money'] ?? 0;
        $landmarksFound = $gameState['player']['stats']['landmarks_found'] ?? 0;
        $foundList = $gameState['worldState']['landmarks_visited'] ?? [];

        $inventory = $this->formatInventory($gameState);
        $note = $this->formatNote($template);
        $nearbyLandmarks = $this->getNearbyLandmarks($template['landmarks'] ?? [], $location, $foundList);
        $weather = $this->getWeather($gameTime, $gameDate);
        $daylight = $this->getDaylight($gameTime);
        $transportInfo = $this->formatTransportation($template);
        $distanceInfo = $this->formatDistances($template, $location);

        return <<<PROMPT
You are the Game Master for a realistic urban survival scavenger hunt set in Portland, Oregon.

THE SITUATION:
The player woke up drugged in Pioneer Courthouse Square with no memory, no money, no phone, and no ID. They have only a crumpled note listing 10 cryptic clues. They must find all 10 landmarks and say "I am here" at each one to win $1,000,000. The note ends ominously with "Or..."

CURRENT STATE:
- Location: {$locationName}
- Time: {$gameTime}
- Date: {$gameDate}
- Weather: {$weather}
- Light: {$daylight}
- Money: \${$money}
- Landmarks Found: {$landmarksFound}/10
- Found: {$this->formatFoundLandmarks($foundList)}

PLAYER INVENTORY:
{$inventory}

THE NOTE (if player has it):
{$note}

CURRENT LOCATION DESCRIPTION:
{$locationDesc}

STREET VIEW: {$streetView}

NEARBY LANDMARKS (distances from current location):
{$nearbyLandmarks}

{$distanceInfo}

TRANSPORTATION OPTIONS:
{$transportInfo}

WORLD RULES - CRITICAL:
1. TIME IS REAL: Walking 1 mile = 20 minutes. Driving without traffic = 3 min/mile. Rush hour (7-9am, 4-6:30pm) = 10 min/mile. MAX light rail = 4 min/mile. Bus = 5 min/mile + 5-20 min wait.

2. GEOGRAPHY IS REAL: Use actual Portland streets, actual distances, actual transit routes. The MAX Blue/Red lines run through downtown. Bus routes are numbered. Streets follow Portland's grid (Burnside divides N/S, Willamette River divides E/W).

3. MONEY MATTERS: Player has \${$money}. TriMet fare is $2.50 (day pass $5). Player cannot use Uber/Lyft without phone and payment. They can beg, find money, work, steal, or convince others.

4. PEOPLE ARE REAL: NPCs should feel real - commuters rushing, homeless people, tourists, baristas, bus drivers. Some help, some ignore, some are dangerous. Conversations take time.

5. CONSEQUENCES ARE REAL: Stealing attracts police. Jaywalking might not. Trespassing has risks. Being wet and cold matters. Time of day affects who's around.

6. THE PLAYER CAN DO ANYTHING: Go anywhere, talk to anyone, try anything. Simulate realistic outcomes. If they want to break into a car, describe the difficulty and risks. If they charm someone into giving them a ride, play it out.

LANDMARK VERIFICATION:
When player reaches a landmark location AND says "I am here" aloud, mark it as found. They must be physically at the location - not just nearby.

RESPONSE FORMAT (JSON):
{
  "narrative": "2-4 paragraphs describing what happens. Be vivid and specific about Portland. Include sensory details. Show time passing if travel occurs.",
  "stateUpdates": {
    "location": {"name": "...", "lat": 0.0, "lng": 0.0, "streetview": "url"},
    "timeAdvance": 0,
    "moneyChange": 0,
    "inventoryChanges": ["add:item" or "remove:item"],
    "landmarkFound": null or "landmark_id",
    "flagChanges": {}
  },
  "currentTime": "HH:MM",
  "streetViewLink": "https://www.google.com/maps?layer=c&cbll=LAT,LNG",
  "suggestedActions": ["action 1", "action 2", "action 3", "action 4"]
}

IMPORTANT:
- Advance time realistically based on actions (walking, waiting, talking)
- Always include the Street View link for the current location
- If player is at a landmark and says "I am here", set landmarkFound to that landmark's ID
- NPCs should have names, appearances, attitudes
- Weather and time of day affect the scene
- This is HARD - don't make it easy. But don't make it impossible.
PROMPT;
    }

    private function buildPortlandUserPrompt(string $normalizedAction, array $gameState): string
    {
        $recentHistory = array_slice($gameState['profile']['actionHistory'] ?? [], -5);
        $historyText = '';
        if (!empty($recentHistory)) {
            $historyText = "\n\nRECENT ACTIONS:\n";
            foreach ($recentHistory as $h) {
                $historyText .= "- Turn {$h['turn']}: {$h['action']}\n";
            }
        }
        return "PLAYER ACTION: {$normalizedAction}{$historyText}";
    }

    private function formatNote(array $template): string
    {
        $note = $template['note'] ?? [];
        if (empty($note)) {
            return 'No note found.';
        }
        return implode("\n", $note['content'] ?? []);
    }

    private function formatInventory(array $gameState): string
    {
        $inv = $gameState['player']['inventory'] ?? [];
        return empty($inv) ? 'Nothing' : implode(', ', $inv);
    }

    private function formatFoundLandmarks(array $found): string
    {
        return empty($found) ? 'None yet' : implode(', ', $found);
    }

    private function getNearbyLandmarks(array $landmarks, array $location, array $found): string
    {
        $currentLat = $location['lat'] ?? 45.5189;
        $currentLng = $location['lng'] ?? -122.6793;

        $nearby = [];
        foreach ($landmarks as $id => $landmark) {
            if (in_array($id, $found)) {
                continue;
            }
            $dist = self::distanceMiles($currentLat, $currentLng, $landmark['lat'], $landmark['lng']);
            $nearby[] = ['id' => $id, 'name' => $landmark['name'], 'distance' => $dist, 'clue' => "Clue #{$landmark['clueIndex']}"];
        }

        usort($nearby, fn($a, $b) => $a['distance'] <=> $b['distance']);
        $nearby = array_slice($nearby, 0, 5);

        $lines = [];
        foreach ($nearby as $l) {
            $miles = round($l['distance'], 1);
            $walkTime = round($miles * 20);
            $lines[] = "- {$l['clue']}: ~{$miles} miles ({$walkTime} min walk)";
        }
        return implode("\n", $lines) ?: 'All landmarks found!';
    }

    private function formatTransportation(array $template): string
    {
        $lines = [];
        foreach ($template['transportation'] ?? [] as $mode => $info) {
            $cost = $info['cost'] ?? 'varies';
            $notes = $info['notes'] ?? '';
            $lines[] = '- ' . ucfirst(str_replace('_', ' ', $mode)) . ": Cost {$cost}. {$notes}";
        }
        return implode("\n", $lines);
    }

    private function formatDistances(array $template, array $location): string
    {
        $locName = $location['name'] ?? '';
        if (stripos($locName, 'Pioneer') !== false) {
            $lines = ['DISTANCES FROM PIONEER SQUARE:'];
            foreach ($template['distances_from_pioneer'] ?? [] as $landmark => $dist) {
                $lines[] = "- {$landmark}: {$dist}";
            }
            return implode("\n", $lines);
        }
        return '';
    }

    /** October in Portland; the hour picks the condition, so it is stable within the hour. */
    private function getWeather(string $time, string $date): string
    {
        $hour = (int) explode(':', $time)[0];
        $conditions = [
            'Overcast, 52°F, light drizzle',
            'Cloudy, 54°F, dry',
            'Foggy, 48°F, damp',
            'Partly cloudy, 56°F',
            'Overcast, 50°F, rain likely',
        ];
        return $conditions[$hour % count($conditions)];
    }

    private function getDaylight(string $time): string
    {
        $hour = (int) explode(':', $time)[0];
        if ($hour < 6) return 'Dark, pre-dawn';
        if ($hour < 7) return 'Dawn breaking, dim light';
        if ($hour < 8) return 'Early morning light';
        if ($hour < 18) return 'Daylight';
        if ($hour < 19) return 'Dusk, fading light';
        if ($hour < 20) return 'Twilight';
        return 'Dark, night';
    }

    /** Great-circle distance in miles (haversine, Earth radius 3959 mi). */
    public static function distanceMiles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 3959;
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
