<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'id' => 1,
            'name' => 'Root User',
            'email' => 'test@example.com',
            'is_root_user' => true,
        ]);
        User::factory()->create([
            'id' => 2,
            'name' => 'Normal User',
            'email' => 'test2@example.com',
        ]);
    }
}
