<?php

namespace Database\Seeders;

use App\Models\Database;
use App\Models\Environment;
use Illuminate\Database\Seeder;

class DBSeeder extends Seeder
{
    public function run(): void
    {
        $environment_1 = Environment::find(1);
        $database_1 = Database::create([
            'id' => 1,
            'name'=> "My first database"
        ]);

        $environment_1->databases()->attach($database_1);
    }
}
