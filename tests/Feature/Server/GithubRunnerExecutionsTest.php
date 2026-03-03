<?php

use App\Enums\GithubRunnerStatus;
use App\Livewire\Server\GithubRunnerExecutions;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders recent github runner executions inside the polled child component', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $privateKeyId = DB::table('private_keys')->insertGetId([
        'uuid' => fake()->uuid(),
        'name' => 'test-key',
        'private_key' => encrypt('test'),
        'team_id' => $team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKeyId,
    ]);

    $githubApp = GithubApp::create([
        'name' => 'Test App',
        'app_id' => 123456,
        'installation_id' => 789,
        'client_id' => 'Iv1.abc',
        'client_secret' => 'secret',
        'webhook_secret' => 'hook-secret',
        'private_key_id' => $privateKeyId,
        'team_id' => $team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'organization' => 'test-org',
    ]);

    $config = GithubRunnerConfig::create([
        'server_id' => $server->id,
        'github_app_id' => $githubApp->id,
        'labels' => ['self-hosted', 'coolify'],
        'max_runners' => 4,
        'capacity_wait_timeout' => 60,
        'runner_user' => 'runner',
        'runner_base_dir' => '/opt/github-runners',
        'is_enabled' => true,
    ]);

    GithubRunnerExecution::create([
        'server_id' => $server->id,
        'github_runner_config_id' => $config->id,
        'status' => GithubRunnerStatus::Running,
        'runner_name' => 'coolify-test-runner',
        'runner_dir' => '/opt/github-runners/coolify-test-runner',
        'workflow_job_id' => 987654,
        'workflow_job_html_url' => 'https://github.com/test-org/test-repo/actions/runs/111/job/987654',
        'repository_full_name' => 'test-org/test-repo',
        'started_at' => now()->subMinute(),
    ]);

    $component = Livewire::test(GithubRunnerExecutions::class, ['server' => $server])
        ->assertSee('Recent Executions')
        ->assertSee('Refresh')
        ->assertSee('coolify-test-runner')
        ->assertSee('Running')
        ->assertSee('Open');

    expect($component->html())->toContain('wire:poll.10s');
    expect($component->html())->toContain('https://github.com/test-org/test-repo/actions/runs/111/job/987654');
});
