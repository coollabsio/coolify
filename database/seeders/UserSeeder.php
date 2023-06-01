<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            "id" => 0,
            'email' => 'test@example.com',
            'is_root_user' => true,
        ]);
        User::factory()->create([
            'email' => 'test2@example.com',
        ]);
    }
}
