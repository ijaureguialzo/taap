<?php

namespace Database\Factories;

use App\Models\Model;
use App\Models\ModelProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Model>
 */
class ModelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => ModelProvider::factory(),
            'model_id' => fake()->unique()->bothify('model-###'),
            'name' => fake()->words(2, true),
            'context_window' => null,
            'max_tokens' => null,
            'capabilities' => null,
            'is_default' => false,
        ];
    }
}
