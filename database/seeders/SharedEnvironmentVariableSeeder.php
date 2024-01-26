<?php

namespace Database\Seeders;

use App\Models\SharedEnvironmentVariable;
use Illuminate\Database\Seeder;

class SharedEnvironmentVariableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SharedEnvironmentVariable::create([
            'key' => 'NODE_ENV',
            'value' => 'team_env',
            'type' => 'team',
            'team_id' => 0,
        ]);
        SharedEnvironmentVariable::create([
            'key' => 'NODE_ENV',
            'value' => 'env_env',
            'type' => 'environment',
            'environment_id' => 1,
            'team_id' => 0,
        ]);
        SharedEnvironmentVariable::create([
            'key' => 'NODE_ENV',
            'value' => 'project_env',
            'type' => 'project',
            'project_id' => 1,
            'team_id' => 0,
        ]);
    }
}
