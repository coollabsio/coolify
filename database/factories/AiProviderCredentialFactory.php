<?php

namespace Database\Factories;

use App\Enums\AiProvider;
use App\Models\AiProviderCredential;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiProviderCredentialFactory extends Factory
{
    protected $model = AiProviderCredential::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'provider' => AiProvider::OPENAI,
            'model' => 'gpt-5',
            'api_key' => 'sk-test-'.$this->faker->uuid(),
            'base_url' => null,
            'is_default' => false,
            'enabled' => true,
        ];
    }
}
