<?php
/**
 * Configuration Helper
 * 
 * Loads API keys and config from keys.json (one level above project root).
 */

/**
 * Load keys.json from one level above the project root.
 * Returns the full decoded array, or empty array if not found.
 */
    


function loadKeysJson(): array
{
    static $cache = null;
    
    if ($cache !== null) {
        return $cache;
    }
    
    $keysFile = '../keys.json';
    
    if (file_exists($keysFile)) {
        $data = json_decode(file_get_contents($keysFile), true);
        $cache = is_array($data) ? $data : [];
    } else {
        $cache = [];
    }
    
    return $cache;
}

/**
 * Get the Anthropic API key from keys.json.
 * Falls back to ANTHROPIC_API_KEY env var.
 */
function getAnthropicApiKey(): string
{
    $keys = loadKeysJson();
    return $keys['anthropic']['key'] ?? (getenv('ANTHROPIC_API_KEY') ?: '');
}

/**
 * Get the Anthropic model from keys.json, with a default fallback.
 */
function getAnthropicModel(string $default = 'claude-sonnet-4-20250514'): string
{
    $keys = loadKeysJson();
    $models = $keys['anthropic']['models'] ?? [];
    return $models[0] ?? $default;
}

/**
 * Get the Anthropic endpoint from keys.json.
 */
function getAnthropicEndpoint(string $default = 'https://api.anthropic.com/v1/messages'): string
{
    $keys = loadKeysJson();
    return $keys['anthropic']['endpoint'] ?? $default;
}
