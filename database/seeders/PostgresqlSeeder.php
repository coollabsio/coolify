<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Postgresql;
use App\Models\StandaloneDocker;

class PostgresqlSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Postgresql::create([
            'name' => 'Local PostgreSQL',
            'description' => 'Local PostgreSQL for testing',
            'postgres_password' => 'postgres',
            'environment_id' => 1,
            'destination_id' => 1,
            'destination_type' => StandaloneDocker::class,
        ]);
    }
}