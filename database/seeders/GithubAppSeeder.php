<?php

namespace Database\Seeders;

use App\Models\GithubApp;
use Illuminate\Database\Seeder;

class GithubAppSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        GithubApp::create([
            'id' => 0,
            'name' => 'Public GitHub',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'is_public' => true,
            'team_id' => 0,
        ]);
        GithubApp::create([
            'name' => 'coolify-laravel-development-public',
            'uuid' => '69420',
            'organization' => 'coollabsio',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'is_public' => false,
            'app_id' => 292941,
            'installation_id' => 37267016,
            'client_id' => 'Iv1.220e564d2b0abd8c',
            'client_secret' => '116d1d80289f378410dd70ab4e4b81dd8d2c52b6',
            'webhook_secret' => '326a47b49054f03288f800d81247ec9414d0abf3',
            'private_key_id' => 2,
            'team_id' => 0,
        ]);
    }
}
