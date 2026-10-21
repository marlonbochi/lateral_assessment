<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Content;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Content>
 */
class ContentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cms_id' => 'content-'.fake()->unique()->numberBetween(1, 9_999_999),
            'version' => fake()->numberBetween(1, 50),
            'status' => ContentStatus::Published,
            'payload' => (object) [
                'title' => fake()->sentence(4),
                'description' => fake()->paragraph(),
            ],
            'cms_timestamp' => now()->subMinutes(fake()->numberBetween(1, 600)),
            'synced_at' => now(),
            'is_disabled' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => ContentStatus::Published]);
    }

    public function unpublished(): static
    {
        return $this->state(['status' => ContentStatus::Unpublished]);
    }

    public function deleted(): static
    {
        return $this->state(['status' => ContentStatus::Deleted, 'payload' => null]);
    }

    public function disabled(): static
    {
        return $this->state(['is_disabled' => true, 'disabled_at' => now()]);
    }

    public function version(int $version): static
    {
        return $this->state(['version' => $version]);
    }
}
