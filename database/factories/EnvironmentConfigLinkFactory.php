<?php

namespace Database\Factories;

use App\Models\EnvironmentConfigLink;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnvironmentConfigLinkFactory extends Factory
{
    protected $model = EnvironmentConfigLink::class;

    public function definition(): array
    {
        return [
            'mode' => EnvironmentConfigLink::MODE_API,
            'file_path' => 'coolify.json',
            'auto_apply' => true,
        ];
    }
}
