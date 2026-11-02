<?php

namespace Database\Seeders;

use App\Models\GitlabApp;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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
        // The explicit id does not move the Postgres sequence, so the next GitLab App would get id 1 again.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval('gitlab_apps_id_seq', (SELECT MAX(id) FROM gitlab_apps))");
        }
    }
}
