<?php

namespace GameMaster\Boundary;

/**
 * AiTransport — performs one ai_call.
 *
 * @phpstan-type AiResult array{
 *   ok: bool,
 *   content: ?string,
 *   usage: array{input: int, output: int},
 *   model: string,
 *   error: ?array{code: string, message: string}
 * }
 */
interface AiTransport
{
    /**
     * @param array $request the ai_call event's data: {system, user, reply, carry}
     * @return array AiResult
     */
    public function call(array $request): array;
}
