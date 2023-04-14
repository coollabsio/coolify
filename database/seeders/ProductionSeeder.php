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
        $coolify_key_name = "id.root@host.docker.internal";
        $coolify_key = Storage::disk('local')->get("ssh-keys/{$coolify_key_name}");
        $coolify_key_in_database = PrivateKey::where('name', 'Coolify Host');

        if (!$coolify_key && $coolify_key_in_database->exists()) {
            Storage::disk('local')->put("ssh-keys/{$coolify_key_name}", $coolify_key_in_database->first()->private_key);
        }
        if ($coolify_key && !$coolify_key_in_database->exists()) {
            PrivateKey::create([
                'name' => 'Coolify Host',
                'description' => 'The private key for the Coolify host machine.',
                'private_key' => $coolify_key,
            ]);
        }
    }
}
