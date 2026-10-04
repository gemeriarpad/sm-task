<?php

namespace Database\Factories;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use App\Models\Target;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subMinutes(fake()->numberBetween(1, 600));

        return [
            'target_id' => Target::factory(),
            'attempt' => 1,
            'status' => SyncRunStatus::Succeeded,
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy()->addSeconds(2),
            'duration_ms' => 2000,
            'repositories_count' => fake()->numberBetween(0, 200),
        ];
    }

    /**
     * Indicate that the attempt failed.
     */
    public function failed(string $error = 'GitHub is having problems right now (HTTP 502).', bool $willRetry = false): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncRunStatus::Failed,
            'repositories_count' => null,
            'error' => $error,
            'will_retry' => $willRetry,
        ]);
    }

    /**
     * Indicate that the attempt is still running.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncRunStatus::Running,
            'finished_at' => null,
            'duration_ms' => null,
            'repositories_count' => null,
        ]);
    }
}
