<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Application Rollback', function () {
    test('setGitImportSettings uses passed commit instead of application git_commit_sha', function () {
        $team = Team::factory()->create();
        $project = Project::create([
            'team_id' => $team->id,
            'name' => 'Test Project',
            'uuid' => (string) str()->uuid(),
        ]);
        $environment = Environment::create([
            'project_id' => $project->id,
            'name' => 'rollback-test-env',
            'uuid' => (string) str()->uuid(),
        ]);
        $server = Server::factory()->create(['team_id' => $team->id]);

        // Create application with git_commit_sha = 'HEAD' (default - use latest)
        $application = Application::factory()->create([
            'environment_id' => $environment->id,
            'destination_id' => $server->id,
            'git_commit_sha' => 'HEAD',
        ]);

        // Create application settings
        ApplicationSetting::create([
            'application_id' => $application->id,
            'is_git_shallow_clone_enabled' => false,
        ]);

        // The rollback commit SHA we want to deploy
        $rollbackCommit = 'abc123def456';

        // This should use the passed commit, not the application's git_commit_sha
        $result = $application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: true,
            commit: $rollbackCommit
        );

        // Assert: The command should checkout the ROLLBACK commit
        expect($result)->toContain($rollbackCommit);
    });
});
