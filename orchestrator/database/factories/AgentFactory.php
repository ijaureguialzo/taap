<?php

namespace Database\Factories;

use App\Enums\AgentRuntime;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => null,
            'runtime' => AgentRuntime::Pi,
            'status' => AgentStatus::Offline,
            'container_name' => 'taap-agent-test-'.fake()->unique()->numberBetween(100000, 999999),
            'container_id' => null,
            'ip' => null,
            'image' => config('taap.runtimes.pi.image'),
            'internet_enabled' => false,
            'auto_restart' => true,
            'model_id' => null,
            'system_prompt' => null,
            'working_dir' => null,
            'config' => null,
            'last_active_at' => null,
            'started_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentStatus::Idle,
            'last_active_at' => now(),
            'started_at' => now(),
        ]);
    }

    public function working(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentStatus::Working,
            'last_active_at' => now(),
            'started_at' => now(),
        ]);
    }

    public function hermes(): static
    {
        return $this->state(fn (array $attributes) => [
            'runtime' => AgentRuntime::Hermes,
            'image' => config('taap.runtimes.hermes.image'),
        ]);
    }
}
