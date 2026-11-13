<?php

namespace GameMaster\Gates;

use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ZoneParseGate — The Interpreter.
 *
 * Parses the AI's zone JSON, validates and bounds it, and falls
 * back to a minimal zone when no JSON can be found. The result
 * settles as residue; the driver takes it and the join (io_collect) picks it up.
 *
 * Signature: zone_ai_replied {gameId, zoneId, parentZoneId, direction, now, ai}
 * Emits:     zone_parsed {gameId, zoneId, zone}
 *            zone = {id, name, description, entities, exits, properties,
 *                    parentZoneId, direction, generated, tokensUsed}
 * Rejects:   zone_failed {gameId, zoneId, error} (abort) — the AI call failed
 */
class ZoneParseGate extends GameGate
{
    private const VALID_LIGHTING = ['dark', 'dim', 'normal', 'bright'];

    public function __construct()
    {
        parent::__construct('zone_ai_replied');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $ai = $d['ai'] ?? [];
        $zoneId = $d['zoneId'];

        if (!($ai['ok'] ?? false)) {
            return new Event('zone_failed', [
                'gameId' => $d['gameId'],
                'zoneId' => $zoneId,
                'error' => $ai['error']['message'] ?? 'Zone generation failed',
            ]);
        }

        $parsed = self::parseResponse((string) ($ai['content'] ?? ''))
            ?? self::fallbackZone($zoneId, $d['parentZoneId'], $d['direction']);
        $parsed = self::validateAndSanitize($parsed, $zoneId);

        return new Event('zone_parsed', [
            'gameId' => $d['gameId'],
            'zoneId' => $zoneId,
            'zone' => [
                'id' => $zoneId,
                'name' => $parsed['name'],
                'description' => $parsed['description'],
                'entities' => $parsed['entities'],
                'exits' => $parsed['exits'],
                'properties' => $parsed['properties'],
                'parentZoneId' => $d['parentZoneId'],
                'direction' => $d['direction'],
                'generated' => $d['now'],
                'tokensUsed' => (int) (($ai['usage']['input'] ?? 0) + ($ai['usage']['output'] ?? 0)),
            ],
        ]);
    }

    private static function parseResponse(string $response): ?array
    {
        $response = trim($response);

        $decoded = json_decode($response, true);
        if (is_array($decoded) && isset($decoded['name'])) {
            return $decoded;
        }
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $response, $m)) {
            $decoded = json_decode(trim($m[1]), true);
            if (is_array($decoded) && isset($decoded['name'])) {
                return $decoded;
            }
        }
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($response, $start, $end - $start + 1), true);
            if (is_array($decoded) && isset($decoded['name'])) {
                return $decoded;
            }
        }
        return null;
    }

    private static function fallbackZone(string $zoneId, ?string $parentZoneId, ?string $direction): array
    {
        $prefixes = ['north' => 'Northern', 'south' => 'Southern', 'east' => 'Eastern', 'west' => 'Western', 'up' => 'Upper', 'down' => 'Lower'];
        $opposites = ['north' => 'south', 'south' => 'north', 'east' => 'west', 'west' => 'east', 'up' => 'down', 'down' => 'up'];

        $prefix = $prefixes[$direction ?? ''] ?? 'Unknown';
        $parts = explode('_', $zoneId);
        $name = count($parts) > 1 ? $prefix . ' ' . ucfirst(end($parts)) : "{$prefix} Area";

        return [
            'name' => $name,
            'description' => "You find yourself in {$name}. The area is shrouded in mystery, waiting to be explored.",
            'entities' => [[
                'id' => $zoneId . '_feature_1',
                'name' => 'Strange Formation',
                'description' => 'An unusual feature catches your attention.',
                'properties' => ['interactable' => true, 'type' => 'object'],
            ]],
            'exits' => [[
                'direction' => $opposites[$direction ?? ''] ?? 'back',
                'targetZoneId' => $parentZoneId,
                'description' => 'The way you came.',
                'locked' => false,
            ]],
            'properties' => ['lighting' => 'dim', 'danger_level' => 1, 'fallback_generated' => true],
        ];
    }

    private static function validateAndSanitize(array $data, string $zoneId): array
    {
        if (empty($data['name']) || !is_string($data['name'])) {
            $data['name'] = 'Unnamed Area';
        }
        $data['name'] = trim(substr($data['name'], 0, 100));

        if (empty($data['description']) || !is_string($data['description'])) {
            $data['description'] = 'An unexplored area.';
        }
        $data['description'] = trim(substr($data['description'], 0, 1000));

        $data['entities'] = self::validateEntities(is_array($data['entities'] ?? null) ? $data['entities'] : [], $zoneId);
        $data['exits'] = self::validateExits(is_array($data['exits'] ?? null) ? $data['exits'] : []);
        $data['properties'] = self::validateProperties(is_array($data['properties'] ?? null) ? $data['properties'] : []);
        return $data;
    }

    private static function validateEntities(array $entities, string $zoneId): array
    {
        $validated = [];
        $counter = 0;
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $counter++;
            $validated[] = [
                'id' => preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) ($entity['id'] ?? "{$zoneId}_entity_{$counter}")),
                'name' => trim(substr((string) ($entity['name'] ?? 'Unknown Object'), 0, 50)),
                'description' => trim(substr((string) ($entity['description'] ?? 'An object.'), 0, 500)),
                'properties' => is_array($entity['properties'] ?? null) ? $entity['properties'] : [],
                'present' => true,
            ];
        }
        return array_slice($validated, 0, 10);
    }

    private static function validateExits(array $exits): array
    {
        $validated = [];
        foreach ($exits as $exit) {
            if (!is_array($exit)) {
                continue;
            }
            $validated[] = [
                'direction' => strtolower((string) ($exit['direction'] ?? 'unknown')),
                'targetZoneId' => $exit['targetZoneId'] ?? null,
                'description' => trim(substr((string) ($exit['description'] ?? 'An exit.'), 0, 200)),
                'locked' => (bool) ($exit['locked'] ?? false),
                'requirements' => is_array($exit['requirements'] ?? null) ? $exit['requirements'] : [],
            ];
        }
        return array_slice($validated, 0, 8);
    }

    private static function validateProperties(array $properties): array
    {
        $lighting = $properties['lighting'] ?? 'normal';
        return [
            'lighting' => in_array($lighting, self::VALID_LIGHTING) ? $lighting : 'normal',
            'danger_level' => max(0, min(10, (int) ($properties['danger_level'] ?? 0))),
            'ambient_sounds' => is_array($properties['ambient_sounds'] ?? null)
                ? array_slice(array_values(array_filter($properties['ambient_sounds'], 'is_string')), 0, 5)
                : [],
            'special_features' => is_array($properties['special_features'] ?? null)
                ? array_slice(array_values(array_filter($properties['special_features'], 'is_string')), 0, 5)
                : [],
        ];
    }
}
