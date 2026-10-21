<?php

declare(strict_types=1);

use App\Domain\Cms\CmsEvent;
use App\Domain\Cms\CmsEventType;

it('builds an event from a validated object, keeping the payload object', function (): void {
    $event = CmsEvent::fromValidated((object) [
        'type' => 'unpublish', 'id' => 'content-1', 'version' => 4,
        'timestamp' => '2026-01-01T10:05:00Z', 'payload' => (object) ['tags' => (object) []],
    ]);

    expect($event->type)->toBe(CmsEventType::Unpublish)
        ->and($event->version)->toBe(4)
        ->and($event->timestamp->format(DATE_ATOM))->toBe('2026-01-01T10:05:00+00:00')
        ->and($event->payload?->tags)->toEqual((object) []);
});

it('drops a payload sent with a delete event', function (): void {
    $event = CmsEvent::fromValidated((object) [
        'type' => 'delete', 'id' => 'content-1', 'version' => 5,
        'timestamp' => '2026-01-01T10:10:00Z', 'payload' => (object) ['ignored' => true],
    ]);

    expect($event->payload)->toBeNull();
});

it('refuses objects that did not pass validation', function (array $overrides): void {
    CmsEvent::fromValidated((object) [
        'type' => 'delete', 'id' => 'content-1', 'version' => 1, 'timestamp' => '2026-01-01T10:00:00Z', ...$overrides,
    ]);
})->with([
    'numeric id' => [['id' => 123]],
    'string version' => [['version' => '1']],
])->throws(InvalidArgumentException::class);
