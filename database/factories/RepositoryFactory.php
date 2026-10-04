<?php

namespace Database\Factories;

use App\Models\Repository;
use App\Models\Target;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'target_id' => Target::factory(),
            'github_id' => fake()->unique()->numberBetween(1, 999_999_999),
            'name' => $name,
            'full_name' => "owner/{$name}",
            'description' => fake()->optional()->sentence(),
            'html_url' => "https://github.com/owner/{$name}",
            'language' => fake()->randomElement(['PHP', 'JavaScript', 'Go', null]),
            'stargazers_count' => fake()->numberBetween(0, 5000),
            'forks_count' => fake()->numberBetween(0, 500),
            'open_issues_count' => fake()->numberBetween(0, 100),
            'fork' => false,
            'archived' => false,
            'github_updated_at' => now()->subDays(2),
            'pushed_at' => now()->subDays(3),
            'last_seen_at' => now(),
        ];
    }

    /**
     * Indicate that the repository is no longer returned by GitHub.
     */
    public function missing(): static
    {
        return $this->state(fn (array $attributes) => [
            'missing_at' => now(),
        ]);
    }
}
