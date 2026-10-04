<?php

namespace Database\Factories;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Target;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Target>
 */
class TargetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->regexify('[A-Za-z][A-Za-z0-9]{3,15}'),
            'type' => fake()->randomElement(TargetType::cases()),
            // Filled by the add form's GitHub check.
            'github_id' => fake()->unique()->numberBetween(1, 99_999_999),
            'avatar_url' => 'https://avatars.githubusercontent.com/u/1',
            'status' => TargetStatus::Queued,
        ];
    }

    /**
     * Indicate that the target was added without its GitHub account details (seeded,
     * created over the API, or added while the form's GitHub check failed).
     */
    public function withoutAccount(): static
    {
        return $this->state(fn (array $attributes) => [
            'github_id' => null,
            'avatar_url' => null,
        ]);
    }

    /**
     * Indicate that the target was synchronized successfully.
     */
    public function synced(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TargetStatus::Synced,
            'last_synced_at' => now(),
        ]);
    }

    /**
     * Indicate that the last synchronization of the target failed.
     */
    public function failed(string $error = 'GitHub is having problems right now (HTTP 502).'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TargetStatus::Failed,
            'last_failed_at' => now(),
            'last_error' => $error,
        ]);
    }

    /**
     * Indicate that the target was deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TargetStatus::Inactive,
            'deactivated_at' => now(),
        ]);
    }
}
