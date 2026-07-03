<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function snapshotTestApplication(array $attributes = []): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::factory()->create(array_merge([
        'environment_id' => $environment->id,
        'status' => 'running:healthy',
        'fqdn' => 'https://example.com',
        'build_command' => 'npm run build',
        'start_command' => 'npm run start',
    ], $attributes));
}

function markSnapshotTestApplicationDeployed(Application $application): ApplicationDeploymentQueue
{
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => (string) $application->id,
        'deployment_uuid' => (string) Str::uuid(),
        'status' => 'finished',
        'commit' => 'HEAD',
    ]);

    $application->markDeploymentConfigurationApplied($deployment);

    return $deployment->refresh();
}

it('does not report preview deployment toggles as pending production configuration changes', function () {
    $application = snapshotTestApplication();
    markSnapshotTestApplicationDeployed($application);

    $application->settings->update(['is_preview_deployments_enabled' => true]);

    expect($application->refresh()->pendingDeploymentConfigurationDiff()->isChanged())->toBeFalse();
});

it('detects build-impacting changes', function () {
    $application = snapshotTestApplication();
    markSnapshotTestApplicationDeployed($application);

    $application->update(['build_command' => 'pnpm build']);
    $diff = $application->refresh()->pendingDeploymentConfigurationDiff();

    expect($diff->isChanged())->toBeTrue()
        ->and($diff->requiresBuild())->toBeTrue()
        ->and(collect($diff->changes())->pluck('label'))->toContain('Build command');
});

it('detects redeploy-only domain changes', function () {
    $application = snapshotTestApplication();
    markSnapshotTestApplicationDeployed($application);

    $application->update(['fqdn' => 'https://new.example.com']);
    $diff = $application->refresh()->pendingDeploymentConfigurationDiff();

    expect($diff->isChanged())->toBeTrue()
        ->and($diff->requiresBuild())->toBeFalse()
        ->and(collect($diff->changes())->pluck('label'))->toContain('Domains');
});

it('detects environment variable value changes without exposing secret values', function () {
    $application = snapshotTestApplication();
    EnvironmentVariable::create([
        'key' => 'API_TOKEN',
        'value' => 'old-secret',
        'is_buildtime' => false,
        'is_runtime' => true,
        'is_preview' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);
    markSnapshotTestApplicationDeployed($application->refresh());

    $application->environment_variables()->where('key', 'API_TOKEN')->first()->update(['value' => 'new-secret']);
    $diff = $application->refresh()->pendingDeploymentConfigurationDiff();
    $change = collect($diff->changes())->firstWhere('label', 'API_TOKEN');

    expect($change)->not->toBeNull()
        ->and($change['display_summary'])->toBe('Changed')
        ->and($change['old_display_value'])->toBe('••••••••')
        ->and($change['new_display_value'])->toBe('••••••••')
        ->and(json_encode($diff->toArray()))->not->toContain('old-secret')->not->toContain('new-secret');
});

it('describes added environment variables as set without exposing secret values', function () {
    $application = snapshotTestApplication();
    markSnapshotTestApplicationDeployed($application);

    EnvironmentVariable::create([
        'key' => 'API_TOKEN',
        'value' => 'new-secret',
        'is_buildtime' => false,
        'is_runtime' => true,
        'is_preview' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $diff = $application->refresh()->pendingDeploymentConfigurationDiff();
    $change = collect($diff->changes())->firstWhere('label', 'API_TOKEN');

    expect($change)->not->toBeNull()
        ->and($change['display_summary'])->toBeNull()
        ->and($change['old_display_value'])->toBe('-')
        ->and($change['new_display_value'])->toBe('••••••••')
        ->and(json_encode($diff->toArray()))->not->toContain('new-secret');
});
