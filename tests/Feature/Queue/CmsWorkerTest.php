<?php

declare(strict_types=1);

use App\Enums\BatchStatus;
use App\Enums\Role;
use App\Models\EventBatch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\postJson;

use Symfony\Component\Yaml\Yaml;

it('processes batches from the configured queue', function (): void {
    config(['cms.queue.name' => 'cms-custom']);

    postBatch([cmsEvent('publish', 'content-1', 1, ['title' => 'A'])])->assertAccepted();

    expect(DB::table('jobs')->where('queue', 'cms-custom')->count())->toBe(1);

    $exitCode = Artisan::call('cms:work', ['--stop-when-empty' => true, '--sleep' => 0]);

    expect($exitCode)->toBe(0)
        ->and(EventBatch::query()->sole()->status)->toBe(BatchStatus::Processed)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('refuses to start when the CMS queue cannot share the inbox transaction', function (array $config): void {
    config($config);

    $exitCode = Artisan::call('cms:work', ['--stop-when-empty' => true, '--sleep' => 0]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('must be a database queue on the application database');
})->with([
    'not a database queue' => [['cms.queue.connection' => 'sync']],
    'a database queue on another database connection' => [['queue.connections.database.connection' => 'other']],
    'an unknown queue connection' => [['cms.queue.connection' => 'missing']],
]);

it('does not acknowledge batches while the CMS queue is misconfigured', function (): void {
    config(['cms.queue.connection' => 'sync']);

    postBatch([cmsEvent('publish', 'content-1', 1, ['title' => 'A'])])
        ->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('Retry-After', '5');

    expect(EventBatch::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('runs the Docker worker through cms:work, so it follows the queue configuration', function (): void {
    /** @var array{services: array{worker: array{command: list<string>}}} $compose */
    $compose = Yaml::parseFile(base_path('docker-compose.yml'));
    $command = implode(' ', $compose['services']['worker']['command']);

    expect($command)->toStartWith('php artisan cms:work')
        ->and($command)->not->toContain('--queue')
        ->and($command)->not->toContain('queue:work');
});

it('answers 503 to an admin retry while the CMS queue is misconfigured', function (): void {
    $batch = EventBatch::factory()->failed()->create();
    actingAsRole(Role::Admin);
    config(['cms.queue.connection' => 'sync']);

    postJson("/api/v1/admin/event-batches/{$batch->id}/retry")
        ->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json');

    expect($batch->fresh()?->status)->toBe(BatchStatus::Failed)
        ->and(DB::table('jobs')->count())->toBe(0);
});
