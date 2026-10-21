<?php

declare(strict_types=1);

use App\Domain\Cms\CmsEventType;
use App\Enums\ContentStatus;

it('maps each CMS event type to the content status it produces', function (CmsEventType $type, ContentStatus $status): void {
    expect($type->targetStatus())->toBe($status);
})->with([
    'publish' => [CmsEventType::Publish, ContentStatus::Published],
    'unpublish' => [CmsEventType::Unpublish, ContentStatus::Unpublished],
    'delete' => [CmsEventType::Delete, ContentStatus::Deleted],
]);

it('parses the wire values sent by the CMS', function (): void {
    expect(CmsEventType::from('publish'))->toBe(CmsEventType::Publish)
        ->and(CmsEventType::tryFrom('archive'))->toBeNull();
});
