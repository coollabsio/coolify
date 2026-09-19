<?php

namespace Database\Factories;

use App\Models\Environment;
use App\Models\InfisicalBinding;
use App\Models\InfisicalConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

class InfisicalBindingFactory extends Factory
{
    protected $model = InfisicalBinding::class;

    public function definition(): array
    {
        return [
            'infisical_connection_id' => InfisicalConnection::factory(),
            'environment_id' => Environment::factory(),
            'infisical_project_id' => $this->faker->uuid(),
            'infisical_environment_slug' => 'prod',
            'secret_path' => '/',
            'is_enabled' => true,
        ];
    }
}
