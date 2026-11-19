<?php

namespace Database\Factories;

use App\Models\ModelProvider;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModelProvider>
 */
class ModelProviderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'type' => 'openai',
            'base_url' => 'http://model-endpoint.local/v1',
            'api_key' => 'test-provider-key',
            'config' => null,
            'created_by' => User::factory(),
        ];
    }
}
