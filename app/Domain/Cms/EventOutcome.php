<?php

declare(strict_types=1);

namespace App\Domain\Cms;

/**
 * What happened to one event of a batch, with the state it was compared to.
 */
final readonly class EventOutcome
{
    public function __construct(
        public int $position,
        public CmsEvent $event,
        public ?ContentSnapshot $previous,
        public EventDecision $decision,
    ) {}
}
