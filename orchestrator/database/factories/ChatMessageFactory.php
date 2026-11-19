<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'author' => 'user',
            'user_id' => User::factory(),
            'content' => fake()->sentence(),
            'meta' => null,
        ];
    }

    public function fromAgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'author' => 'agent',
            'user_id' => null,
        ]);
    }
}
