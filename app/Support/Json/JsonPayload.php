<?php

declare(strict_types=1);

namespace App\Support\Json;

use JsonException;

/**
 * JSON helpers that keep JSON objects as objects.
 *
 * PHP's associative decoding turns {} into [], silently changing CMS data on a
 * round trip. Decoding with objects (stdClass) keeps the distinction.
 */
final class JsonPayload
{
    private const int ENCODE_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Decode an untrusted request body. Integers beyond int64 become strings
     * (so validation rejects them) instead of silently turning into floats.
     *
     * @throws JsonException
     */
    public static function decodeBody(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }

    /**
     * Decode JSON that was already stored by this service.
     *
     * @throws JsonException
     */
    public static function decode(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    public static function encode(mixed $value): string
    {
        return json_encode($value, self::ENCODE_FLAGS);
    }
}
