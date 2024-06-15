<?php

namespace Database\Factories;

use App\Models\Application;
use Illuminate\Database\Eloquent\Factories\Factory;
use Visus\Cuid2\Cuid2;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ApplicationDeploymentQueue>
 */
class ApplicationDeploymentQueueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'deployment_uuid' => (string) new Cuid2(7),
            'commit' => '81024772fb19308dd49c21ac7968cc340b1a0784',
        ];
    }
}
