<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * ResponseParseGate — The Translator.
 *
 * Extracts the structured JSON from the AI's reply, validates
 * it and falls back to a plain-text narrative when no JSON can
 * be found. Then asks for the current save, which the parsed
 * changes will be applied to.
 *
 * Signature: turn_ai_replied {gameId, action, now, ai: {ok, content, usage, model, error}}
 * Emits:     io_read {save} → turn_apply_loaded
 *            carry {gameId, action, now, parsed, usage, model}
 *            `parsed` is the whole sanitised AI object: world-specific
 *            fields (Portland's location, timeAdvance, …) pass through.
 * Rejects:   turn_failed (abort) — the AI call failed
 */
class ResponseParseGate extends GameGate
{
    private const DEFAULT_SUGGESTIONS = ['Look around', 'Check inventory', 'Examine surroundings'];

    public function __construct()
    {
        parent::__construct('turn_ai_replied');
    }

    public function transform(Event $event): Event
    {
        $d = $event->data;
        $ai = $d['ai'] ?? [];

        if (!($ai['ok'] ?? false)) {
            return new Event('turn_failed', [
                'code' => $ai['error']['code'] ?? 'AI_REQUEST_FAILED',
                'message' => $ai['error']['message'] ?? 'AI API request failed',
                'hint' => 'Try your action again',
            ]);
        }

        $content = (string) ($ai['content'] ?? '');
        $parsed = self::parseResponse($content) ?? [
            'narrative' => self::extractNarrativeFromText($content),
            'stateUpdates' => [],
            'suggestedActions' => self::DEFAULT_SUGGESTIONS,
        ];

        return Io::read(['save' => Io::save($d['gameId'])], 'turn_apply_loaded', [
            'gameId' => $d['gameId'],
            'action' => $d['action'],
            'now' => $d['now'],
            'parsed' => self::validateAndSanitize($parsed),
            'usage' => $ai['usage'] ?? ['input' => 0, 'output' => 0],
            'model' => $ai['model'] ?? 'unknown',
        ]);
    }

    public static function parseResponse(string $response): ?array
    {
        $json = self::extractJSON($response);
        if ($json === null) {
            return null;
        }
        $parsed = json_decode($json, true);
        if (!is_array($parsed) || !isset($parsed['narrative']) || !is_string($parsed['narrative'])) {
            return null;
        }
        return $parsed;
    }

    private static function extractJSON(string $response): ?string
    {
        $response = trim($response);

        // 1. The whole reply is JSON
        $decoded = json_decode($response, true);
        if (is_array($decoded) && isset($decoded['narrative'])) {
            return $response;
        }

        // 2. ```json code block
        if (preg_match('/```json\s*\n?(.*?)\n?\s*```/s', $response, $m)) {
            $potential = trim($m[1]);
            if (json_decode($potential, true) !== null) {
                return $potential;
            }
        }

        // 3. ``` code block without a language
        if (preg_match('/```\s*\n?(.*?)\n?\s*```/s', $response, $m)) {
            $potential = trim($m[1]);
            $decoded = json_decode($potential, true);
            if (is_array($decoded) && isset($decoded['narrative'])) {
                return $potential;
            }
        }

        // 4. First balanced {...} that has a narrative
        $json = self::extractJSONByBraces($response);
        if ($json !== null) {
            return $json;
        }

        // 5. A flat object that starts with "narrative"
        if (preg_match('/\{\s*"narrative"\s*:\s*"[^"]*"[^}]*\}/s', $response, $m)) {
            if (json_decode($m[0], true) !== null) {
                return $m[0];
            }
        }

        return null;
    }

    private static function extractJSONByBraces(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($text);

        for ($i = $start; $i < $len; $i++) {
            $char = $text[$i];
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($char === '\\' && $inString) {
                $escape = true;
                continue;
            }
            if ($char === '"') {
                $inString = !$inString;
                continue;
            }
            if ($inString) {
                continue;
            }
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $potential = substr($text, $start, $i - $start + 1);
                    $decoded = json_decode($potential, true);
                    if (is_array($decoded) && isset($decoded['narrative'])) {
                        return $potential;
                    }
                    $start = strpos($text, '{', $i + 1);
                    if ($start === false) {
                        return null;
                    }
                    $i = $start - 1;
                    $depth = 0;
                }
            }
        }
        return null;
    }

    private static function extractNarrativeFromText(string $response): string
    {
        $text = preg_replace('/\{[^{}]*\}/', '', $response);
        $text = preg_replace('/```[^`]*```/', '', $text);
        $text = preg_replace('/[*_`#]/', '', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if (empty($text)) {
            return 'You consider your options carefully.';
        }
        if (strlen($text) > 1000) {
            $text = substr($text, 0, 997) . '...';
        }
        return $text;
    }

    private static function validateAndSanitize(array $parsed): array
    {
        if (empty($parsed['narrative']) || !is_string($parsed['narrative'])) {
            $parsed['narrative'] = 'You consider your options carefully.';
        }
        $parsed['narrative'] = htmlspecialchars_decode(strip_tags($parsed['narrative']), ENT_QUOTES);

        if (!isset($parsed['stateUpdates']) || !is_array($parsed['stateUpdates'])) {
            $parsed['stateUpdates'] = [];
        }

        $entityChanges = $parsed['stateUpdates']['entityChanges'] ?? [];
        $parsed['stateUpdates']['entityChanges'] = array_values(array_filter(
            is_array($entityChanges) ? $entityChanges : [],
            fn($c) => is_array($c) && isset($c['entityId']) && is_scalar($c['entityId'])
                && (!isset($c['properties']) || is_array($c['properties']))
        ));

        $invChanges = $parsed['stateUpdates']['inventoryChanges'] ?? [];
        $parsed['stateUpdates']['inventoryChanges'] = array_values(array_filter(
            is_array($invChanges) ? $invChanges : [],
            fn($c) => is_string($c) && (str_starts_with($c, 'add:') || str_starts_with($c, 'remove:'))
        ));

        $stat = $parsed['stateUpdates']['statChanges'] ?? [];
        $parsed['stateUpdates']['statChanges'] = is_array($stat) ? $stat : [];

        $flag = $parsed['stateUpdates']['flagChanges'] ?? [];
        $parsed['stateUpdates']['flagChanges'] = is_array($flag) ? $flag : [];

        $suggestions = $parsed['suggestedActions'] ?? [];
        if (!is_array($suggestions)) {
            $suggestions = [];
        }
        $suggestions = array_values(array_filter(
            array_map(fn($s) => is_string($s) ? trim($s) : null, $suggestions),
            fn($s) => !empty($s)
        ));
        $suggestions = array_slice($suggestions, 0, 6);
        $parsed['suggestedActions'] = empty($suggestions) ? self::DEFAULT_SUGGESTIONS : $suggestions;

        if (isset($parsed['profileInsight']) && !is_string($parsed['profileInsight'])) {
            unset($parsed['profileInsight']);
        }

        $promises = $parsed['newPromises'] ?? [];
        $parsed['newPromises'] = is_array($promises) ? $promises : [];

        return $parsed;
    }
}
