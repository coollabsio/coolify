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
            'name' => 'ideploy-dev-app-example',
            'uuid' => '69420',
            'organization' => 'iniitydev',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'is_public' => false,
            'app_id' => null, // User must replace this
            'installation_id' => null, // User must replace this
            'client_id' => 'your-client-id', // User must replace this
            'client_secret' => 'your-client-secret', // User must replace this
            'webhook_secret' => 'your-webhook-secret', // User must replace this
            'private_key_id' => null, // User must replace this (or setup a new private key)
            'team_id' => 0, // Belongs to system team by default
        ]);
    }
}
