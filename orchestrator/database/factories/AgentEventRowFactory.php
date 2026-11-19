<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentEventRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentEventRow>
 */
class AgentEventRowFactory extends Factory
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
            'type' => 'status.change',
            'payload' => [],
            'occurred_at' => now(),
        ];
    }
}
