<?php

declare(strict_types=1);

use App\Domain\Cms\CmsEvent;
use App\Domain\Cms\CmsEventType;
use App\Domain\Cms\ContentSnapshot;
use App\Domain\Cms\EventPrecedence;
use App\Domain\Cms\IgnoreReason;
use App\Enums\ContentStatus;

function precedenceEvent(CmsEventType $type, int $version, string $timestamp = '2026-01-01T10:00:00Z'): CmsEvent
{
    return new CmsEvent(
        id: 'content-1',
        version: $version,
        timestamp: new DateTimeImmutable($timestamp),
        type: $type,
        payload: $type->requiresPayload() ? (object) ['title' => 'x'] : null,
    );
}

it('applies the first event for an entity at any version and with any type', function (CmsEventType $type, int $version, ContentStatus $expected): void {
    $decision = (new EventPrecedence)->decide(null, precedenceEvent($type, $version));

    expect($decision->apply)->toBeTrue()
        ->and($decision->newStatus)->toBe($expected)
        ->and($decision->ignoreReason)->toBeNull();
})->with([
    'publish v1' => [CmsEventType::Publish, 1, ContentStatus::Published],
    'unpublish v7 (never seen before)' => [CmsEventType::Unpublish, 7, ContentStatus::Unpublished],
    'delete v3 (tombstone first)' => [CmsEventType::Delete, 3, ContentStatus::Deleted],
]);

it('applies any event with a higher version, including version gaps', function (ContentStatus $current, CmsEventType $type, ContentStatus $expected): void {
    $decision = (new EventPrecedence)->decide(new ContentSnapshot(3, $current), precedenceEvent($type, 9));

    expect($decision->apply)->toBeTrue()->and($decision->newStatus)->toBe($expected);
})->with([
    'published → publish (content replaced)' => [ContentStatus::Published, CmsEventType::Publish, ContentStatus::Published],
    'published → unpublish' => [ContentStatus::Published, CmsEventType::Unpublish, ContentStatus::Unpublished],
    'published → delete' => [ContentStatus::Published, CmsEventType::Delete, ContentStatus::Deleted],
    'unpublished → publish' => [ContentStatus::Unpublished, CmsEventType::Publish, ContentStatus::Published],
    'unpublished → unpublish' => [ContentStatus::Unpublished, CmsEventType::Unpublish, ContentStatus::Unpublished],
    'unpublished → delete' => [ContentStatus::Unpublished, CmsEventType::Delete, ContentStatus::Deleted],
    'deleted → publish (re-created)' => [ContentStatus::Deleted, CmsEventType::Publish, ContentStatus::Published],
    'deleted → unpublish (re-created)' => [ContentStatus::Deleted, CmsEventType::Unpublish, ContentStatus::Unpublished],
    'deleted → delete (version advanced)' => [ContentStatus::Deleted, CmsEventType::Delete, ContentStatus::Deleted],
]);

it('handles the edge case: unpublish of a version that was never published or received', function (): void {
    $decision = (new EventPrecedence)->decide(
        new ContentSnapshot(3, ContentStatus::Published),
        precedenceEvent(CmsEventType::Unpublish, 4),
    );

    expect($decision->apply)->toBeTrue()->and($decision->newStatus)->toBe(ContentStatus::Unpublished);
});

it('ignores an event with the same version as a duplicate', function (ContentStatus $current, CmsEventType $type): void {
    $decision = (new EventPrecedence)->decide(new ContentSnapshot(5, $current), precedenceEvent($type, 5));

    expect($decision->apply)->toBeFalse()
        ->and($decision->ignoreReason)->toBe(IgnoreReason::Duplicate)
        ->and($decision->newStatus)->toBeNull();
})->with([
    'published + publish' => [ContentStatus::Published, CmsEventType::Publish],
    'unpublished + publish' => [ContentStatus::Unpublished, CmsEventType::Publish],
    'deleted + publish' => [ContentStatus::Deleted, CmsEventType::Publish],
    'deleted + delete' => [ContentStatus::Deleted, CmsEventType::Delete],
]);

it('ignores an event with a lower version as stale', function (ContentStatus $current, CmsEventType $type, IgnoreReason $reason): void {
    $decision = (new EventPrecedence)->decide(new ContentSnapshot(5, $current), precedenceEvent($type, 4));

    expect($decision->apply)->toBeFalse()->and($decision->ignoreReason)->toBe($reason);
})->with([
    'published + older publish' => [ContentStatus::Published, CmsEventType::Publish, IgnoreReason::Stale],
    'unpublished + older delete' => [ContentStatus::Unpublished, CmsEventType::Delete, IgnoreReason::Stale],
    'deleted + older publish (must not restore)' => [ContentStatus::Deleted, CmsEventType::Publish, IgnoreReason::StaleAfterDelete],
    'deleted + older unpublish (must not restore)' => [ContentStatus::Deleted, CmsEventType::Unpublish, IgnoreReason::StaleAfterDelete],
]);

it('never uses timestamps to decide precedence', function (): void {
    $precedence = new EventPrecedence;

    // Higher version with an OLDER timestamp still wins.
    $newer = $precedence->decide(
        new ContentSnapshot(2, ContentStatus::Published),
        precedenceEvent(CmsEventType::Unpublish, 3, '2020-01-01T00:00:00Z'),
    );
    // Lower version with a NEWER timestamp still loses.
    $older = $precedence->decide(
        new ContentSnapshot(2, ContentStatus::Published),
        precedenceEvent(CmsEventType::Unpublish, 1, '2030-01-01T00:00:00Z'),
    );

    expect($newer->apply)->toBeTrue()
        ->and($older->apply)->toBeFalse()
        ->and($older->ignoreReason)->toBe(IgnoreReason::Stale);
});
