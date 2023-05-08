<?php

namespace Database\Seeders;

use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class GithubAppSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $root_team = Team::find(0);
        $private_key_1 = PrivateKey::find(1);
        $private_key_2 = PrivateKey::find(2);
        GithubApp::create([
            'name' => 'Public GitHub',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'is_public' => true,
            'team_id' => $root_team->id,
        ]);
        GithubApp::create([
            'name' => 'coolify-laravel-development-private-github',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'is_public' => false,
            'app_id' => 292941,
            'installation_id' => 37267016,
            'client_id' => 'Iv1.220e564d2b0abd8c',
            'client_secret' => '96b1b31f36ce0a34386d11798ff35b9b6d8aba3a',
            'webhook_secret' => '326a47b49054f03288f800d81247ec9414d0abf3',
            'private_key_id' => $private_key_2->id,
            'team_id' => $root_team->id,
        ]);
    }
}
