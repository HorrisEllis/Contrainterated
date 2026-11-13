<?php

namespace GameMaster\Pure;

/**
 * Ids — pure checks and normalisers for identifiers that
 * end up in resource names (save files, zone files).
 */
final class Ids
{
    public const PATTERN = '/^[A-Za-z0-9_-]{1,100}\z/';

    /** True for 1-100 characters of [A-Za-z0-9_-]. */
    public static function isValid(mixed $id): bool
    {
        return is_string($id) && preg_match(self::PATTERN, $id) === 1;
    }

    /** Remove every character outside [A-Za-z0-9_-]. */
    public static function strip(string $id): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    }

    /** Replace every character outside [A-Za-z0-9_-] with '_'. */
    public static function underscore(string $id): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);
    }
}
