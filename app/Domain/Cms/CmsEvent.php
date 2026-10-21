<?php

declare(strict_types=1);

namespace App\Domain\Cms;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use stdClass;

/**
 * One change notification from the CMS.
 *
 * The payload is kept as a decoded JSON object (stdClass) so empty objects
 * ({}) are never confused with empty lists ([]).
 */
final readonly class CmsEvent
{
    public function __construct(
        public string $id,
        public int $version,
        public DateTimeImmutable $timestamp,
        public CmsEventType $type,
        public ?stdClass $payload,
    ) {}

    /**
     * Build from an event that already passed request validation, decoded
     * with objects preserved (json_decode(..., false)).
     */
    public static function fromValidated(object $raw): self
    {
        $type = CmsEventType::from(self::string($raw, 'type'));
        $payload = $raw->payload ?? null;

        return new self(
            id: self::string($raw, 'id'),
            version: self::int($raw, 'version'),
            // Normalized to UTC: any RFC 3339 offset (up to ±23:59) is then
            // storable, while PostgreSQL only accepts offsets up to ±15:59.
            timestamp: (new DateTimeImmutable(self::string($raw, 'timestamp')))->setTimezone(new DateTimeZone('UTC')),
            type: $type,
            // Delete events may carry a payload; it is ignored.
            payload: $type->requiresPayload() && $payload instanceof stdClass ? $payload : null,
        );
    }

    private static function string(object $raw, string $field): string
    {
        $value = $raw->{$field} ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("CMS event field [{$field}] must be a string.");
        }

        return $value;
    }

    private static function int(object $raw, string $field): int
    {
        $value = $raw->{$field} ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException("CMS event field [{$field}] must be an integer.");
        }

        return $value;
    }
}
