<?php

namespace Database\Seeders;

use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        // Save SSH Keys for the Coolify Host
        $coolify_key_name = "id.root@host.docker.internal";
        $coolify_key = Storage::disk('local')->get("ssh-keys/{$coolify_key_name}");
        $coolify_key_in_database = PrivateKey::where('name', 'Coolify Host');

        if (!$coolify_key && $coolify_key_in_database->exists()) {
            Storage::disk('local')->put("ssh-keys/{$coolify_key_name}", $coolify_key_in_database->first()->private_key);
        }
        if ($coolify_key && !$coolify_key_in_database->exists()) {
            PrivateKey::create([
                'id' => 0,
                'name' => 'localhost\'s key',
                'description' => 'The private key for the Coolify host machine (localhost).',
                'private_key' => $coolify_key,
            ]);
        }

        // Add first Team if it doesn't exist
        if (Team::find(0) == null) {
            Team::create([
                'id' => 0,
                'name' => "Root's Team",
                'personal_team' => true,
            ]);
        }
        // Add Coolify host (localhost) as Server if it doesn't exist
        if (Server::find(0) == null) {
            Server::create([
                'id' => 0,
                'name' => "localhost",
                'description' => "This is the local machine",
                'user' => 'root',
                'ip' => "host.docker.internal",
                'team_id' => 0,
                'private_key_id' => 0,
            ]);
        }
    }
}
