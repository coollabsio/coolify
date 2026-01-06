<?php

namespace Database\Seeders;

use App\Models\GitlabApp;
use Illuminate\Database\Seeder;

class GitlabAppSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        GitlabApp::create([
            'id' => 1,
            'uuid' => 'gitlab-public',
            'name' => 'Public GitLab',
            'api_url' => 'https://gitlab.com/api/v4',
            'html_url' => 'https://gitlab.com',
            'is_public' => true,
            'team_id' => 0,
        ]);
    }
}
