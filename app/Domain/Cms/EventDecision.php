<?php

declare(strict_types=1);

namespace App\Domain\Cms;

use App\Enums\ContentStatus;

/**
 * Outcome of evaluating one event against the current state.
 */
final readonly class EventDecision
{
    private function __construct(
        public bool $apply,
        public ?ContentStatus $newStatus,
        public ?IgnoreReason $ignoreReason,
    ) {}

    public static function apply(ContentStatus $newStatus): self
    {
        return new self(true, $newStatus, null);
    }

    public static function ignore(IgnoreReason $reason): self
    {
        return new self(false, null, $reason);
    }
}
