<?php

namespace Database\Seeders;

use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $coolify_key = Storage::disk('local')->get('ssh-keys/coolify.dsa');
        if (PrivateKey::where('name', 'Coolify Host')->doesntExist()) {
            PrivateKey::create([
                "id" => 0,
                "name" => "Coolify Host",
                "description" => "This is the private key for the server where Coolify is hosted.",
                "private_key" => $coolify_key,
            ]);
        } else {
            dump('Coolify SSH Key already exists.');
        }
    }
}
