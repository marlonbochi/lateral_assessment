<?php

declare(strict_types=1);

use App\Enums\BatchStatus;
use App\Enums\ContentStatus;
use App\Enums\Role;
use App\Models\Content;
use App\Models\EventBatch;
use App\Models\User;

it('creates consumers for each role', function (): void {
    expect(User::factory()->user()->create()->role)->toBe(Role::User)
        ->and(User::factory()->admin()->create()->role)->toBe(Role::Admin);
});

it('creates content in every CMS state, satisfying the database constraints', function (): void {
    $published = Content::factory()->published()->create();
    $unpublished = Content::factory()->unpublished()->create();
    $deleted = Content::factory()->deleted()->create();
    $disabled = Content::factory()->published()->disabled()->create();
    $versioned = Content::factory()->version(42)->create();

    expect($published->fresh()?->status)->toBe(ContentStatus::Published)
        ->and($unpublished->fresh()?->status)->toBe(ContentStatus::Unpublished)
        ->and($deleted->fresh()?->status)->toBe(ContentStatus::Deleted)
        ->and($deleted->fresh()?->getRawOriginal('payload'))->toBeNull()
        ->and($disabled->fresh()?->is_disabled)->toBeTrue()
        ->and($disabled->fresh()?->disabled_at)->not->toBeNull()
        ->and($versioned->fresh()?->version)->toBe(42);
});

it('addresses content by its CMS identifier', function (): void {
    expect((new Content)->getRouteKeyName())->toBe('cms_id');
});

it('creates event batches in every lifecycle state with time-ordered uuid keys', function (): void {
    $pending = EventBatch::factory()->pending()->create();
    $processed = EventBatch::factory()->processed()->create();
    $failed = EventBatch::factory()->failed()->create();

    expect($pending->fresh()?->status)->toBe(BatchStatus::Pending)
        ->and($processed->fresh()?->status)->toBe(BatchStatus::Processed)
        ->and($processed->fresh()?->processed_at)->not->toBeNull()
        ->and($failed->fresh()?->status)->toBe(BatchStatus::Failed)
        ->and($failed->fresh()?->failed_at)->not->toBeNull()
        ->and($pending->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/');
});
