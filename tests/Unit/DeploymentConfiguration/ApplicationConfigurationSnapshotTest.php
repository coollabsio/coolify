<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Team;
use App\Services\DeploymentConfiguration\ConfigurationDiffer;
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

    $domains = 'https://new.example.com,https://another.example.com';
    $application->update(['fqdn' => $domains]);
    $diff = $application->refresh()->pendingDeploymentConfigurationDiff();
    $change = collect($diff->changes())->firstWhere('label', 'Domains');

    expect($diff->isChanged())->toBeTrue()
        ->and($diff->requiresBuild())->toBeFalse()
        ->and($change)->not->toBeNull()
        ->and($change['expandable'])->toBeTrue()
        ->and($change['new_full_value'])->toBe($domains);
});

it('detects Docker image reference changes as redeploy-only changes', function (string $field, string $label, string $newValue) {
    $application = snapshotTestApplication([
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'ghcr.io/coollabsio/shoutrrr',
        'docker_registry_image_tag' => '1.3.0-rc.4',
    ]);
    markSnapshotTestApplicationDeployed($application);

    $application->update([$field => $newValue]);
    $diff = $application->refresh()->pendingDeploymentConfigurationDiff();
    $change = collect($diff->changes())->firstWhere('label', $label);

    expect($diff->isChanged())->toBeTrue()
        ->and($diff->requiresBuild())->toBeFalse()
        ->and($change)->not->toBeNull()
        ->and($change['old_display_value'])->not->toBe($change['new_display_value'])
        ->and($change['new_display_value'])->toBe($newValue);
})->with([
    'image name' => ['docker_registry_image_name', 'Docker image', 'ghcr.io/coollabsio/coolify'],
    'image tag' => ['docker_registry_image_tag', 'Docker image tag or hash', '1.3.0-rc.5'],
]);

it('detects deployment hook changes as redeploy-only changes', function (string $field, string $label, string $newValue) {
    $application = snapshotTestApplication();
    markSnapshotTestApplicationDeployed($application);

    $application->update([$field => $newValue]);
    $diff = $application->refresh()->pendingDeploymentConfigurationDiff();

    expect($diff->requiresBuild())->toBeFalse()
        ->and(collect($diff->changes())->pluck('label'))->toContain($label);
})->with([
    'pre-deployment command' => ['pre_deployment_command', 'Pre-deployment command', 'php artisan migrate --force'],
    'pre-deployment container' => ['pre_deployment_command_container', 'Pre-deployment command container', 'web'],
    'post-deployment command' => ['post_deployment_command', 'Post-deployment command', 'php artisan cache:clear'],
    'post-deployment container' => ['post_deployment_command_container', 'Post-deployment command container', 'worker'],
]);

it('detects source integration changes as build changes', function (string $field, string $label, mixed $newValue) {
    $application = snapshotTestApplication([
        'source_id' => 1,
        'source_type' => 'App\\Models\\GithubApp',
    ]);
    markSnapshotTestApplicationDeployed($application);

    $application->forceFill([$field => $newValue])->save();
    $diff = $application->refresh()->pendingDeploymentConfigurationDiff();

    expect($diff->requiresBuild())->toBeTrue()
        ->and(collect($diff->changes())->pluck('label'))->toContain($label);
})->with([
    'source ID' => ['source_id', 'Source ID', 2],
    'source type' => ['source_type', 'Source type', 'App\\Models\\GitlabApp'],
]);

it('detects build-affecting application settings', function (string $field, string $label) {
    $application = snapshotTestApplication();
    $application->settings->update([$field => false]);
    markSnapshotTestApplicationDeployed($application);

    $application->settings->update([$field => true]);
    $diff = $application->refresh()->pendingDeploymentConfigurationDiff();

    expect($diff->requiresBuild())->toBeTrue()
        ->and(collect($diff->changes())->pluck('label'))->toContain($label);
})->with([
    'static site' => ['is_static', 'Static site'],
    'single-page application' => ['is_spa', 'Single-page application'],
    'Git submodules' => ['is_git_submodules_enabled', 'Git submodules'],
    'Git LFS' => ['is_git_lfs_enabled', 'Git LFS'],
    'shallow clone' => ['is_git_shallow_clone_enabled', 'Shallow clone'],
    'environment variable sorting' => ['is_env_sorting_enabled', 'Sort environment variables'],
]);

it('detects runtime-affecting application settings as redeploy-only changes', function (string $field, string $label, mixed $newValue) {
    $application = snapshotTestApplication();
    $application->settings->update([$field => is_bool($newValue) ? ! $newValue : null]);
    markSnapshotTestApplicationDeployed($application);

    $application->settings->update([$field => $newValue]);
    $diff = $application->refresh()->pendingDeploymentConfigurationDiff();

    expect($diff->requiresBuild())->toBeFalse()
        ->and(collect($diff->changes())->pluck('label'))->toContain($label);
})->with([
    'consistent container name' => ['is_consistent_container_name_enabled', 'Consistent container name', true],
    'container label escaping' => ['is_container_label_escape_enabled', 'Escape container labels', false],
    'container labels read-only' => ['is_container_label_readonly_enabled', 'Read-only container labels', false],
    'log drain' => ['is_log_drain_enabled', 'Log drain', true],
    'Swarm worker nodes' => ['is_swarm_only_worker_nodes', 'Swarm worker nodes only', true],
    'stop grace period' => ['stop_grace_period', 'Stop grace period', 45],
    'preserve repository' => ['is_preserve_repository_enabled', 'Preserve repository', true],
]);

it('classifies runtime options as redeploy-only and custom nginx configuration as build-affecting', function () {
    $application = snapshotTestApplication();
    markSnapshotTestApplicationDeployed($application);

    $application->update(['custom_docker_run_options' => '--init']);

    expect($application->refresh()->pendingDeploymentConfigurationDiff()->requiresBuild())->toBeFalse();

    markSnapshotTestApplicationDeployed($application->refresh());
    $application->update(['custom_nginx_configuration' => 'server { listen 80; }']);

    expect($application->refresh()->pendingDeploymentConfigurationDiff()->requiresBuild())->toBeTrue();
});

it('keeps Docker run options compatible with older build-section snapshots', function () {
    $application = snapshotTestApplication(['custom_docker_run_options' => '--init'])->refresh();
    $previousSnapshot = $application->deploymentConfigurationSnapshot();
    $buildItems = collect(data_get($previousSnapshot, 'sections.build.items'));

    expect($buildItems->pluck('key'))->toContain('custom_docker_run_options');

    data_set(
        $previousSnapshot,
        'sections.build.items',
        $buildItems->map(function (array $item): array {
            if ($item['key'] === 'custom_docker_run_options') {
                $item['impact'] = 'build';
            }

            return $item;
        })->all(),
    );

    expect(app(ConfigurationDiffer::class)->diff($previousSnapshot, $application->deploymentConfigurationSnapshot())->isChanged())->toBeFalse();

    $application->update(['custom_docker_run_options' => '--rm']);

    expect(app(ConfigurationDiffer::class)->diff($previousSnapshot, $application->refresh()->deploymentConfigurationSnapshot())->requiresBuild())->toBeFalse();
});

it('does not report newly tracked settings when an older snapshot omitted their default values', function () {
    $application = snapshotTestApplication();
    $application->settings->update(['stop_grace_period' => DEFAULT_STOP_GRACE_PERIOD_SECONDS]);
    $currentSnapshot = $application->deploymentConfigurationSnapshot();
    $introducedKeys = [
        'is_static',
        'is_spa',
        'is_git_submodules_enabled',
        'is_git_lfs_enabled',
        'is_git_shallow_clone_enabled',
        'is_env_sorting_enabled',
        'is_consistent_container_name_enabled',
        'is_container_label_escape_enabled',
        'is_container_label_readonly_enabled',
        'is_log_drain_enabled',
        'is_swarm_only_worker_nodes',
        'is_preserve_repository_enabled',
        'stop_grace_period',
    ];
    $previousSnapshot = $currentSnapshot;

    foreach (['build', 'runtime'] as $section) {
        data_set(
            $previousSnapshot,
            "sections.{$section}.items",
            collect(data_get($previousSnapshot, "sections.{$section}.items"))
                ->reject(fn (array $item): bool => in_array($item['key'], $introducedKeys, true))
                ->values()
                ->all(),
        );
    }

    expect(app(ConfigurationDiffer::class)->diff($previousSnapshot, $currentSnapshot)->isChanged())->toBeFalse();
});

it('accepts the historical environment sorting default in older snapshots', function () {
    $application = snapshotTestApplication();
    $application->settings->update(['is_env_sorting_enabled' => true]);
    $currentSnapshot = $application->deploymentConfigurationSnapshot();
    $previousSnapshot = $currentSnapshot;
    data_set(
        $previousSnapshot,
        'sections.build.items',
        collect(data_get($previousSnapshot, 'sections.build.items'))
            ->reject(fn (array $item): bool => $item['key'] === 'is_env_sorting_enabled')
            ->values()
            ->all(),
    );

    expect(app(ConfigurationDiffer::class)->diff($previousSnapshot, $currentSnapshot)->isChanged())->toBeFalse();
});

it('detects environment variable value changes without exposing secret values', function () {
    $application = snapshotTestApplication();
    EnvironmentVariable::create([
        'key' => 'API_TOKEN',
        'value' => 'old-secret',
        'is_buildtime' => false,
        'is_runtime' => true,
        'is_preview' => false,
        'is_shown_once' => true,
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
        'is_shown_once' => true,
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
