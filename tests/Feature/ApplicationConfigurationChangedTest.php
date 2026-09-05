<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function configurationChangedTestApplication(array $attributes = []): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::factory()->create(array_merge([
        'environment_id' => $environment->id,
        'status' => 'running:healthy',
        'build_command' => 'npm run build',
    ], $attributes));
}

function configurationChangedDeployment(Application $application): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => (string) $application->id,
        'deployment_uuid' => (string) Str::uuid(),
        'status' => 'finished',
        'commit' => 'HEAD',
    ]);
}

it('stores deployment configuration snapshot and clears pending changes', function () {
    $application = configurationChangedTestApplication();
    $deployment = configurationChangedDeployment($application);

    $application->markDeploymentConfigurationApplied($deployment);

    expect($deployment->refresh()->configuration_hash)->not->toBeNull()
        ->and($deployment->configuration_snapshot)->toBeArray()
        ->and($application->refresh()->pendingDeploymentConfigurationDiff()->isChanged())->toBeFalse();
});

it('stores a diff between successful deployments', function () {
    $application = configurationChangedTestApplication();
    $firstDeployment = configurationChangedDeployment($application);
    $application->markDeploymentConfigurationApplied($firstDeployment);

    $application->update(['build_command' => 'pnpm build']);
    $secondDeployment = configurationChangedDeployment($application->refresh());
    $application->markDeploymentConfigurationApplied($secondDeployment);

    expect($secondDeployment->refresh()->configuration_diff['count'])->toBe(1)
        ->and(data_get($secondDeployment->configuration_diff, 'changes.0.label'))->toBe('Build command');
});

it('reports noindex domain changes as requiring a redeploy', function () {
    $application = configurationChangedTestApplication([
        'fqdn' => 'https://app.example.com,https://staging.example.com',
    ]);
    $deployment = configurationChangedDeployment($application);
    $application->markDeploymentConfigurationApplied($deployment);

    $application->setNoindexDomains(['https://staging.example.com']);
    $application->save();

    $diff = $application->refresh()->pendingDeploymentConfigurationDiff();
    $change = collect($diff->changes())->firstWhere('key', 'domains.noindex_domains');

    expect($diff->isChanged())->toBeTrue()
        ->and($change)->not->toBeNull()
        ->and($change['label'])->toBe('Search engine indexing')
        ->and($change['impact'])->toBe('redeploy');
});

it('reports domain port-only changes as requiring a redeploy', function () {
    $application = configurationChangedTestApplication([
        'fqdn' => 'https://app.example.com',
        'domain_port_overrides' => ['https://app.example.com' => 3000],
    ]);
    $deployment = configurationChangedDeployment($application);
    $application->markDeploymentConfigurationApplied($deployment);

    $application->update([
        'domain_port_overrides' => ['https://app.example.com' => 8080],
    ]);

    $diff = $application->refresh()->pendingDeploymentConfigurationDiff();
    $change = collect($diff->changes())->firstWhere('key', 'domains.domain_port_overrides');

    expect($diff->isChanged())->toBeTrue()
        ->and($change)->not->toBeNull()
        ->and($change['impact'])->toBe('redeploy');
});

it('keeps application deployment snapshots stable when port overrides are reordered', function () {
    $application = configurationChangedTestApplication([
        'fqdn' => 'https://one.example.com,https://two.example.com',
        'domain_port_overrides' => [
            'https://one.example.com' => 3000,
            'https://two.example.com' => 8080,
        ],
    ]);
    $deployment = configurationChangedDeployment($application);
    $application->markDeploymentConfigurationApplied($deployment);

    $application->update([
        'domain_port_overrides' => [
            'https://two.example.com' => 8080,
            'https://one.example.com' => 3000,
        ],
    ]);

    expect($application->refresh()->pendingDeploymentConfigurationDiff()->isChanged())->toBeFalse();
});

it('does not flag applications whose older snapshot omitted noindex domains', function () {
    $application = configurationChangedTestApplication([
        'fqdn' => 'https://app.example.com',
    ]);
    $deployment = configurationChangedDeployment($application);
    $application->markDeploymentConfigurationApplied($deployment);

    $snapshot = $deployment->refresh()->configuration_snapshot;
    $snapshot['sections']['domains']['items'] = collect($snapshot['sections']['domains']['items'])
        ->reject(fn (array $item): bool => $item['key'] === 'noindex_domains')
        ->values()
        ->all();
    $deployment->update(['configuration_snapshot' => $snapshot]);

    expect($application->refresh()->pendingDeploymentConfigurationDiff()->isChanged())->toBeFalse();
});

it('checks legacy preview deployment configuration hash using preview environment variable query', function () {
    $application = configurationChangedTestApplication();

    EnvironmentVariable::create([
        'key' => 'APP_ENV',
        'value' => 'preview',
        'is_preview' => true,
        'is_multiline' => false,
        'is_literal' => false,
        'is_buildtime' => true,
        'is_runtime' => true,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $application->forceFill([
        'config_hash' => 'legacy-hash',
        'pull_request_id' => 123,
    ]);

    $diff = $application->pendingDeploymentConfigurationDiff();

    expect($diff->isChanged())->toBeTrue()
        ->and($diff->count())->toBeGreaterThan(0);
});

it('falls back to real diff against empty snapshot when no deployment snapshot exists', function () {
    $application = configurationChangedTestApplication();
    $application->isConfigurationChanged(save: true);

    expect($application->refresh()->pendingDeploymentConfigurationDiff()->isChanged())->toBeFalse();

    $application->update(['build_command' => 'pnpm build']);

    $diff = $application->refresh()->pendingDeploymentConfigurationDiff();

    expect($diff->isChanged())->toBeTrue()
        ->and($diff->isLegacyFallback())->toBeFalse()
        ->and($diff->count())->toBeGreaterThan(0)
        ->and(collect($diff->changes())->pluck('label')->toArray())->toContain('Build command');
});
