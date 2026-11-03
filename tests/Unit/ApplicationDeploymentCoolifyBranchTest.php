<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

class TestableCoolifyBranchDeploymentJob extends ApplicationDeploymentJob
{
    public function __construct() {}

    public function execute_remote_command(...$commands): void {}
}

/**
 * @return array{shell: string, env: array<string, mixed>}
 */
function coolifyBranchVariables(string $buildPack, int $pullRequestId, string $checkedOutBranch): array
{
    $team = Team::create(['name' => 'Branch Team', 'personal_team' => false, 'show_boarding' => false]);
    $project = Project::create(['name' => 'Branch Project', 'team_id' => $team->id]);
    $environment = Environment::where('project_id', $project->id)->firstOrFail();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $server->standaloneDockers()->firstOrFail()->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => $buildPack,
        'git_branch' => 'main',
    ]);
    $preview = $pullRequestId === 0 ? null : ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => $pullRequestId,
        'pull_request_html_url' => 'https://github.com/coollabsio/example/pull/'.$pullRequestId,
        'fqdn' => 'https://pr.example.com',
    ]);

    $job = new TestableCoolifyBranchDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    foreach ([
        'application' => $application->fresh(),
        'pull_request_id' => $pullRequestId,
        'preview' => $preview,
        'commit' => 'HEAD',
        'branch' => $checkedOutBranch,
    ] as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    $reflection->getMethod('set_coolify_variables')->invoke($job);

    return [
        'shell' => $reflection->getProperty('coolify_variables')->getValue($job),
        'env' => $reflection->getMethod('generate_coolify_env_variables')->invoke($job, true)->all(),
    ];
}

it('uses the pull request ref for COOLIFY_BRANCH in compose variables of a preview deployment', function () {
    $variables = coolifyBranchVariables('dockercompose', 7, 'main');

    expect($variables['shell'])->toContain("COOLIFY_BRANCH='pull/7/head'");
});

it('uses the pull request ref for COOLIFY_BRANCH in env variables of a preview deployment', function () {
    $variables = coolifyBranchVariables('dockerfile', 7, 'pull/7/head:pr-7-coolify');

    expect($variables['env']['COOLIFY_BRANCH'])->toBe('pull/7/head')
        ->and($variables['shell'])->toContain("COOLIFY_BRANCH='pull/7/head'");
});

it('uses the application branch for COOLIFY_BRANCH of a production deployment', function () {
    $variables = coolifyBranchVariables('dockerfile', 0, 'main');

    expect($variables['env']['COOLIFY_BRANCH'])->toBe('main')
        ->and($variables['shell'])->toContain("COOLIFY_BRANCH='main'");
});
