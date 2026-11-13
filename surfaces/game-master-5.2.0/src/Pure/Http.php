<?php

namespace GameMaster\Pure;

use GameMaster\Events\Io;
use Siso\Core\Event;

/**
 * Http — pure builders for http_response events.
 *
 * The body shapes are the ones the REST API has always
 * returned (formerly GameMaster\Http\Response's static
 * helpers), so the frontend contract is unchanged.
 */
final class Http
{
    public static function success(mixed $data = null, string $message = 'Success', int $status = 200): Event
    {
        return Io::http($status, ['success' => true, 'message' => $message, 'data' => $data]);
    }

    public static function error(string $message, int $status = 400, array $errors = []): Event
    {
        $body = ['success' => false, 'error' => $message];
        if (!empty($errors)) {
            $body['errors'] = $errors;
        }
        return Io::http($status, $body);
    }

    public static function notFound(string $message = 'Not found'): Event
    {
        return self::error($message, 404);
    }

    public static function validationError(array $errors): Event
    {
        return self::error('Validation failed', 422, $errors);
    }

    public static function serverError(string $message = 'Internal server error'): Event
    {
        return self::error($message, 500);
    }

    /**
     * A failed turn, in the shape the action endpoint has always
     * used for in-world failures (formerly GameResponseEvent::toArray
     * plus the budget meta block): HTTP 422.
     */
    public static function turnFailure(string $narrative, string $error, array $suggestedActions = []): Event
    {
        return Io::http(422, [
            'success' => false,
            'data' => [
                'narrative' => $narrative,
                'suggestedActions' => $suggestedActions,
                'player' => [],
                'turnCount' => 0,
                'currentZone' => '',
                'lastSaved' => null,
            ],
            'error' => $error,
            'meta' => ['tokensUsed' => 0, 'turnTokens' => 0],
        ]);
    }
}
