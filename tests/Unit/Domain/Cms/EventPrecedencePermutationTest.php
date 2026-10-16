<?php

declare(strict_types=1);

use App\Domain\Cms\CmsEvent;
use App\Domain\Cms\CmsEventType;
use App\Domain\Cms\ContentSnapshot;
use App\Domain\Cms\EventPrecedence;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * SC-001: for any permutation and duplication of a set of events, the final
 * state of every entity equals the state implied by its highest-version event.
 */
it('converges to the highest-version event regardless of order and duplicates', function (int $seed): void {
    $random = new Randomizer(new Mt19937($seed));
    $types = CmsEventType::cases();

    // 1–3 entities, 1–6 events each, unique increasing versions with random gaps.
    $events = [];
    $expected = [];
    foreach (range(1, $random->getInt(1, 3)) as $n) {
        $id = "content-{$n}";
        $version = 0;
        foreach (range(1, $random->getInt(1, 6)) as $_) {
            $version += $random->getInt(1, 4);
            $type = $types[$random->getInt(0, count($types) - 1)];
            $events[] = new CmsEvent($id, $version, new DateTimeImmutable('2026-01-01T00:00:00Z'), $type, $type->requiresPayload() ? (object) ['v' => $version] : null);
            $expected[$id] = [$version, $type->targetStatus()];
        }
    }

    // Duplicate a random subset (redelivery), then shuffle (out-of-order delivery).
    foreach ($events as $event) {
        if ($random->getInt(0, 2) === 0) {
            $events[] = $event;
        }
    }
    $events = $random->shuffleArray($events);

    $precedence = new EventPrecedence;
    /** @var array<string, ContentSnapshot> $state */
    $state = [];
    foreach ($events as $event) {
        $decision = $precedence->decide($state[$event->id] ?? null, $event);
        if ($decision->apply && $decision->newStatus !== null) {
            $state[$event->id] = new ContentSnapshot($event->version, $decision->newStatus);
        }
    }

    foreach ($expected as $id => [$version, $status]) {
        expect($state[$id]->version)->toBe($version)
            ->and($state[$id]->status)->toBe($status);
    }
})->with(fn (): array => range(1, 200));
