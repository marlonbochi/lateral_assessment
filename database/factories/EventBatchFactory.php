<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BatchStatus;
use App\Models\EventBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventBatch>
 */
class EventBatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $events = [[
            'type' => 'publish',
            'id' => 'content-'.fake()->unique()->numberBetween(1, 9_999_999),
            'version' => 1,
            'timestamp' => '2026-01-01T10:00:00Z',
            'payload' => ['title' => fake()->sentence(3)],
        ]];

        return [
            'status' => BatchStatus::Pending,
            'events' => json_encode($events, JSON_THROW_ON_ERROR),
            'event_count' => count($events),
            'attempts' => 0,
            'received_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => BatchStatus::Pending]);
    }

    public function processed(): static
    {
        return $this->state([
            'status' => BatchStatus::Processed,
            'attempts' => 1,
            'applied_count' => 1,
            'ignored_count' => 0,
            'processed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status' => BatchStatus::Failed,
            'attempts' => 5,
            'last_error' => 'RuntimeException: simulated failure',
            'failed_at' => now(),
        ]);
    }
}
