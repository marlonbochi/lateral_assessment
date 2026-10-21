<?php

declare(strict_types=1);

namespace App\Support\Json;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A JSON document kept as text, so it reaches the response exactly as
 * PostgreSQL stored it.
 *
 * Decoding in PHP would turn integers beyond int64 and high-precision decimals
 * into floats (or INF, which cannot be encoded at all). Instead, a resource
 * puts a RawJson in its array: json_encode() writes a placeholder for it, and
 * embed() swaps each placeholder for the original text.
 *
 * The placeholder starts with a NUL character, which json_encode() always
 * escapes as \u0000 and which PostgreSQL never allows inside jsonb strings.
 * Replacement is a single pass, so embedded documents are never rescanned.
 */
final readonly class RawJson implements JsonSerializable, Stringable
{
    private const string MARKER = "\u{0}raw-json:";

    private const string PLACEHOLDER_PATTERN = '/"\\\\u0000raw-json:([A-Za-z0-9_-]*)"/';

    public function __construct(public string $json)
    {
        if (! json_validate($json)) {
            throw new InvalidArgumentException('RawJson requires a valid JSON document.');
        }
    }

    public function isObject(): bool
    {
        return str_starts_with(ltrim($this->json), '{');
    }

    public function jsonSerialize(): string
    {
        return self::MARKER.rtrim(strtr(base64_encode($this->json), '+/', '-_'), '=');
    }

    /**
     * Replace every placeholder in an encoded JSON document with its original text.
     */
    public static function embed(string $encoded): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            fn (array $match): string => (string) base64_decode(strtr($match[1], '-_', '+/'), true),
            $encoded,
        );
    }

    public function __toString(): string
    {
        return $this->json;
    }
}
