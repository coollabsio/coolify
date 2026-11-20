<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\OriginApp;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Http::fake([
        'https://api.cursor.com/v1/origin/app/installations/i_acme/access_tokens' => Http::response(['token' => 'oit_secret+token', 'expiresAt' => now()->addMinutes(5)->toRfc3339String()]),
        'https://api.cursor.com/v1/origin/repos/acme/rocket/pulls/17' => Http::response(['number' => '17', 'head' => ['ref' => 'add-telemetry', 'sha' => str_repeat('a', 40)]]),
    ]);

    $team = Team::factory()->create();
    $originApp = OriginApp::factory()->create(['team_id' => $team->id, 'app_id' => 'app_coolify', 'installation_id' => 'i_acme']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = Server::factory()->create(['team_id' => $team->id])->standaloneDockers()->firstOrFail();

    $this->application = Application::create([
        'name' => 'origin-app',
        'git_repository' => 'acme/rocket',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'source_id' => $originApp->id,
        'source_type' => OriginApp::class,
    ]);
});

test('clones an Origin repository over HTTPS with an installation token', function () {
    $result = $this->application->generateGitImportCommands('deploy-1', exec_in_docker: false);

    expect($result['fullRepoUrl'])->toBe('https://x-access-token:oit_secret%2Btoken@origin.cursor.com/acme/rocket.git')
        ->and($result['commands'])->toStartWith("git -c 'url.https://x-access-token:oit_secret%2Btoken@origin.cursor.com/.insteadOf=https://origin.cursor.com/' clone")
        ->and($result['commands'])->toContain("-b 'main' 'https://x-access-token:oit_secret%2Btoken@origin.cursor.com/acme/rocket.git' '/artifacts/deploy-1'");

    Http::assertSent(fn ($request) => $request->url() === 'https://api.cursor.com/v1/origin/app/installations/i_acme/access_tokens'
        && str_starts_with($request->header('Authorization')[0], 'Bearer ey'));
});

test('checks out the head branch of an Origin pull request', function () {
    $result = $this->application->generateGitImportCommands('deploy-1', pull_request_id: 17, git_type: 'origin', exec_in_docker: false);

    expect($result['branch'])->toBe('refs/heads/add-telemetry:pr-17-coolify')
        ->and($result['commands'])->toContain("fetch origin 'refs/heads/add-telemetry:pr-17-coolify'")
        ->and($result['commands'])->toContain("checkout 'pr-17-coolify'");
});

test('ls-remote uses the authenticated Origin URL', function () {
    $result = $this->application->generateGitLsRemoteCommands('deploy-1', exec_in_docker: false);

    expect($result['commands'])->toBe("git ls-remote 'https://x-access-token:oit_secret%2Btoken@origin.cursor.com/acme/rocket.git'");
});

test('repository links point to the Origin codebase page', function () {
    expect($this->application->git_branch_location)->toBe('https://cursor.com/codebase/acme/rocket')
        ->and($this->application->gitCommitLink('abc1234'))->toBe('https://cursor.com/codebase/acme/rocket');
});
