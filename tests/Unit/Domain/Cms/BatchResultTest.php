<?php

declare(strict_types=1);

use App\Domain\Cms\BatchResult;
use App\Domain\Cms\CmsEvent;
use App\Domain\Cms\CmsEventType;
use App\Domain\Cms\EventDecision;
use App\Domain\Cms\EventOutcome;
use App\Domain\Cms\IgnoreReason;
use App\Enums\ContentStatus;

it('counts applied and ignored outcomes', function (): void {
    $event = new CmsEvent('content-1', 1, new DateTimeImmutable, CmsEventType::Delete, null);
    $result = new BatchResult('batch-1', 2, [
        new EventOutcome(0, $event, null, EventDecision::apply(ContentStatus::Deleted)),
        new EventOutcome(1, $event, null, EventDecision::ignore(IgnoreReason::Duplicate)),
        new EventOutcome(2, $event, null, EventDecision::ignore(IgnoreReason::Stale)),
    ]);

    expect($result->applied())->toBe(1)
        ->and($result->ignored())->toBe(2)
        ->and($result->attempt)->toBe(2)
        ->and($result->alreadyProcessed)->toBeFalse();
});

it('represents a batch that was already processed (at-least-once delivery)', function (): void {
    $result = BatchResult::alreadyProcessed('batch-1');

    expect($result->alreadyProcessed)->toBeTrue()
        ->and($result->batchId)->toBe('batch-1')
        ->and($result->applied())->toBe(0)
        ->and($result->ignored())->toBe(0);
});
