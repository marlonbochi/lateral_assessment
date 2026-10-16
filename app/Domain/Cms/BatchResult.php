<?php

declare(strict_types=1);

namespace App\Domain\Cms;

/**
 * Result of processing one batch, in received order.
 */
final readonly class BatchResult
{
    /**
     * @param  list<EventOutcome>  $outcomes
     */
    public function __construct(
        public string $batchId,
        public int $attempt,
        public array $outcomes,
        public bool $alreadyProcessed = false,
    ) {}

    public static function alreadyProcessed(string $batchId): self
    {
        return new self($batchId, 0, [], true);
    }

    public function applied(): int
    {
        return count(array_filter($this->outcomes, fn (EventOutcome $o): bool => $o->decision->apply));
    }

    public function ignored(): int
    {
        return count($this->outcomes) - $this->applied();
    }
}
