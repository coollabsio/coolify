<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Project\Application\DeploymentNavbar;
use App\Livewire\Project\Application\Domains;
use App\Livewire\Project\Application\General;
use App\Livewire\Project\Application\PreviewDomains;
use App\Livewire\Project\Application\Previews;
use App\Livewire\Project\Application\Rollback;
use App\Livewire\Project\Application\Swarm;
use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    $this->withoutVite();
    Server::flushIdentityMap();
    Queue::fake();
    config(['app.maintenance.driver' => 'file']);

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['id' => 0, 'is_dns_validation_enabled' => false],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'name' => 'Audited App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => null,
        'redirect' => 'both',
        'build_pack' => 'nixpacks',
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'is_http_basic_auth_enabled' => false,
        'git_repository' => 'coollabsio/coolify',
        'git_branch' => 'main',
        'ports_exposes' => '3000',
        'swarm_replicas' => 1,
    ]);
});

function applicationSettingsAuditEvents(string $event): Collection
{
    return AuditEvent::query()->where('event', $event)->orderBy('id')->get();
}

function createAuditedApplicationPreview(Application $application, int $pullRequestId, array $attributes = []): ApplicationPreview
{
    return ApplicationPreview::create(array_merge([
        'application_id' => $application->id,
        'pull_request_id' => $pullRequestId,
        'pull_request_html_url' => "https://github.com/coollabsio/coolify/pull/{$pullRequestId}",
    ], $attributes));
}

it('audits preview deployment settings and skips the audit when nothing changed', function () {
    $component = Livewire::test(Previews::class, ['application' => $this->application])
        ->set('isPreviewDeploymentsEnabled', true)
        ->set('isPrDeploymentsPublicEnabled', true)
        ->call('savePreviewSettings')
        ->assertHasNoErrors();

    $events = applicationSettingsAuditEvents('ui.application.settings_updated');
    expect($events)->toHaveCount(1)
        ->and($events->first()->team_id)->toBe($this->team->id)
        ->and($events->first()->resource_uuid)->toBe($this->application->uuid)
        ->and($events->first()->metadata['application_name'])->toBe('Audited App')
        ->and($events->first()->metadata['changed_fields'])
        ->toEqualCanonicalizing(['is_preview_deployments_enabled', 'is_pr_deployments_public_enabled']);

    $component->call('savePreviewSettings')->assertHasNoErrors();

    expect(applicationSettingsAuditEvents('ui.application.settings_updated'))->toHaveCount(1);
});

it('does not audit preview settings when the user cannot update the application', function () {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    Livewire::test(Previews::class, ['application' => $this->application])
        ->set('isPreviewDeploymentsEnabled', true)
        ->call('savePreviewSettings')
        ->assertForbidden();

    expect(AuditEvent::query()->where('event', 'ui.application.settings_updated')->exists())->toBeFalse();
});

it('audits adding, retagging and deleting a preview', function () {
    $component = Livewire::test(Previews::class, ['application' => $this->application])
        ->set('parameters', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ])
        ->call('add', 42, 'https://github.com/coollabsio/coolify/pull/42');

    $created = applicationSettingsAuditEvents('ui.application.preview_created');
    expect($created)->toHaveCount(1)
        ->and($created->first()->metadata['pull_request_id'])->toBe(42)
        ->and($created->first()->resource_uuid)->toBe($this->application->uuid);

    $component->set('previewDockerTags.0', 'pr-42')
        ->call('save_preview', ApplicationPreview::query()->where('pull_request_id', 42)->value('id'));

    $updated = applicationSettingsAuditEvents('ui.application.preview_updated');
    expect($updated)->toHaveCount(1)
        ->and($updated->first()->metadata['pull_request_id'])->toBe(42)
        ->and($updated->first()->metadata['changed_fields'])->toBe(['docker_registry_image_tag']);

    $component->call('delete', 42);

    $deleted = applicationSettingsAuditEvents('ui.application.preview_deleted');
    expect($deleted)->toHaveCount(1)
        ->and($deleted->first()->metadata['pull_request_id'])->toBe(42);
});

it('audits preview domain changes', function () {
    $preview = createAuditedApplicationPreview($this->application, 7, [
        'fqdn' => 'https://one-pr-7.example.com,https://two-pr-7.example.com',
    ]);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->call('removeDomain', 1)
        ->assertHasNoErrors();

    $events = applicationSettingsAuditEvents('ui.application.preview_updated');
    expect($preview->fresh()->fqdn)->toBe('https://one-pr-7.example.com')
        ->and($events)->toHaveCount(1)
        ->and($events->first()->metadata['pull_request_id'])->toBe(7)
        ->and($events->first()->metadata['changed_fields'])->toBe(['fqdn']);
});

it('audits application setting changes from each settings control', function (Closure $change, string $field) {
    $change->call($this);

    $events = applicationSettingsAuditEvents('ui.application.settings_updated');
    expect($events)->toHaveCount(1)
        ->and($events->first()->resource_uuid)->toBe($this->application->uuid)
        ->and($events->first()->metadata['changed_fields'])->toBe([$field]);
})->with([
    'general settings' => [function () {
        Livewire::test(General::class, ['application' => $this->application])
            ->set('isPreserveRepositoryEnabled', true)
            ->call('instantSave')
            ->assertHasNoErrors();
    }, 'is_preserve_repository_enabled'],
    'force https' => [function () {
        $this->application->update(['fqdn' => 'https://app.example.com']);
        Livewire::test(Domains::class, ['application' => $this->application->fresh()])
            ->set('isForceHttpsEnabled', false)
            ->call('updateForceHttps')
            ->assertHasNoErrors();
    }, 'is_force_https_enabled'],
    'swarm worker nodes' => [function () {
        $current = (bool) $this->application->settings->is_swarm_only_worker_nodes;
        Livewire::test(Swarm::class, ['application' => $this->application])
            ->set('isSwarmOnlyWorkerNodes', ! $current)
            ->call('instantSave')
            ->assertHasNoErrors();
    }, 'is_swarm_only_worker_nodes'],
    'deployment debug logs' => [function () {
        $deployment = ApplicationDeploymentQueue::create([
            'application_id' => $this->application->id,
            'deployment_uuid' => 'audit-debug-toggle',
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
        ]);
        Livewire::test(DeploymentNavbar::class, ['application_deployment_queue' => $deployment])
            ->call('show_debug');
    }, 'is_debug_enabled'],
    'rollback image retention' => [function () {
        Livewire::test(Rollback::class, ['application' => $this->application])
            ->set('dockerImagesToKeep', 5)
            ->call('saveSettings')
            ->assertHasNoErrors();
    }, 'docker_images_to_keep'],
    'build secrets' => [function () {
        Livewire::test(All::class, ['resource' => $this->application])
            ->set('use_build_secrets', true)
            ->call('instantSave')
            ->assertHasNoErrors();
    }, 'use_build_secrets'],
]);
