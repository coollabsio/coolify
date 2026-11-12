<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    SharedEnvironmentVariable::create([
        'key' => 'TEAM_SECRET', 'value' => 'team-secret-value', 'type' => 'team', 'team_id' => $team->id,
    ]);

    $this->application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => <<<'YAML'
services:
  web:
    image: nginx:alpine
    environment:
      CHANGED_FOR_PREVIEW: ''
      SAME_AS_PRODUCTION: ''
      FROM_TEAM: '{{team.TEAM_SECRET}}'
YAML,
    ]);

    // Creating a production variable also creates a preview copy with the same value.
    foreach (['CHANGED_FOR_PREVIEW', 'SAME_AS_PRODUCTION'] as $key) {
        EnvironmentVariable::create([
            'key' => $key,
            'value' => 'production-value',
            'resourceable_type' => Application::class,
            'resourceable_id' => $this->application->id,
            'is_preview' => false,
        ]);
    }
    $this->application->environment_variables_preview()->where('key', 'CHANGED_FOR_PREVIEW')->firstOrFail()
        ->update(['value' => 'preview-value']);
});

function composePreviewEnvironmentValues(Application $application, int $pullRequestId = 0): array
{
    $previewId = null;
    if ($pullRequestId !== 0) {
        $previewId = ApplicationPreview::create([
            'application_id' => $application->id,
            'pull_request_id' => $pullRequestId,
            'pull_request_html_url' => "https://github.com/example/repository/pull/{$pullRequestId}",
        ])->id;
    }

    $serviceName = $pullRequestId === 0 ? 'web' : "web-pr-{$pullRequestId}";
    $environment = collect(data_get($application->fresh()->parse(pull_request_id: $pullRequestId, preview_id: $previewId)->toArray(), "services.{$serviceName}.environment"));

    return $environment->only(['CHANGED_FOR_PREVIEW', 'SAME_AS_PRODUCTION', 'FROM_TEAM'])->all();
}

it('fills empty compose values of a production deployment with the production variables', function () {
    expect(composePreviewEnvironmentValues($this->application))->toBe([
        'CHANGED_FOR_PREVIEW' => 'production-value',
        'SAME_AS_PRODUCTION' => 'production-value',
        'FROM_TEAM' => 'team-secret-value',
    ]);
});

it('fills empty compose values of a pull request deployment with the preview variables', function () {
    expect(composePreviewEnvironmentValues($this->application, 7))->toBe([
        'CHANGED_FOR_PREVIEW' => 'preview-value',
        'SAME_AS_PRODUCTION' => 'production-value',
        'FROM_TEAM' => 'team-secret-value',
    ]);
});

it('gives a public pull request deployment no production values or shared variables from the compose file', function () {
    $this->application->settings->update(['is_pr_deployments_public_enabled' => true]);

    expect(composePreviewEnvironmentValues($this->application, 7))->toBe([
        'CHANGED_FOR_PREVIEW' => 'preview-value',
        'SAME_AS_PRODUCTION' => '',
        'FROM_TEAM' => '{{team.TEAM_SECRET}}',
    ]);
});

it('resolves shared variables in a preview value of a public pull request deployment', function () {
    $this->application->settings->update(['is_pr_deployments_public_enabled' => true]);
    $this->application->environment_variables_preview()->where('key', 'CHANGED_FOR_PREVIEW')->firstOrFail()
        ->update(['value' => '{{team.TEAM_SECRET}}']);

    expect(composePreviewEnvironmentValues($this->application, 7)['CHANGED_FOR_PREVIEW'])->toBe('team-secret-value');
});
