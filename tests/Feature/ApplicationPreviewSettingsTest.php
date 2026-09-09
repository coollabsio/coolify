<?php

use App\Livewire\Project\Application\Previews;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config(['app.maintenance.driver' => 'file']);

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        [
            'id' => 0,
            'is_dns_validation_enabled' => false,
        ]
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $key = PrivateKey::withoutEvents(fn () => PrivateKey::forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $key->id,
        'ip' => '203.0.113.10',
    ]);

    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Preview App',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => null,
        'redirect' => 'both',
        'build_pack' => 'nixpacks',
    ]);

    $this->application->settings()->update([
        'is_container_label_readonly_enabled' => true,
    ]);
});

it('shows preview settings and persists changes independently of preview inputs', function () {
    Livewire::test(Previews::class, ['application' => $this->application])
        ->assertSee('PR deployment access')
        ->assertSet('isPreviewDeploymentsEnabled', false)
        ->set('manualPullRequestId', -1)
        ->set('isPreviewDeploymentsEnabled', true)
        ->set('isPrDeploymentsPublicEnabled', true)
        ->call('savePreviewSettings')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($this->application->fresh()->settings)
        ->is_preview_deployments_enabled->toBeTrue()
        ->is_pr_deployments_public_enabled->toBeTrue();

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->assertSet('isPreviewDeploymentsEnabled', true)
        ->assertSet('isPrDeploymentsPublicEnabled', true)
        ->set('isPreviewDeploymentsEnabled', false)
        ->call('savePreviewSettings');

    expect($this->application->fresh()->settings->is_preview_deployments_enabled)->toBeFalse();
});

it('renders preview deployment enablement as a section action', function () {
    Livewire::test(Previews::class, ['application' => $this->application])
        ->assertSee('Enable preview deployments')
        ->assertDontSeeHtml('id="isPreviewDeploymentsEnabled"')
        ->call('togglePreviewDeployments')
        ->assertSee('Disable preview deployments')
        ->assertSet('isPreviewDeploymentsEnabled', true);

    expect($this->application->fresh()->settings->is_preview_deployments_enabled)->toBeTrue();
});

it('does not show git preview settings for non-git applications', function (string $buildPack, ?string $dockerfile) {
    $this->application->update(['build_pack' => $buildPack, 'dockerfile' => $dockerfile]);

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->assertDontSee('PR deployment access');
})->with([['dockerimage', null], ['dockerfile', 'FROM nginx']]);

it('denies preview setting changes without application update permission', function (string $role, bool $otherTeam) {
    $user = User::factory()->create();
    $team = $otherTeam ? Team::factory()->create() : $this->team;
    $team->members()->attach($user->id, ['role' => $role]);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(Previews::class, ['application' => $this->application])
        ->set('isPreviewDeploymentsEnabled', true)
        ->set('isPrDeploymentsPublicEnabled', true)
        ->call('savePreviewSettings')
        ->assertForbidden();

    expect($this->application->fresh()->settings)
        ->is_preview_deployments_enabled->toBeFalse()
        ->is_pr_deployments_public_enabled->toBeFalse();
})->with([['member', false], ['owner', true]]);

it('removes preview settings from Advanced including its persistence path', function () {
    expect(file_get_contents(resource_path('views/livewire/project/application/advanced.blade.php')))
        ->not->toContain('isPreviewDeploymentsEnabled', 'isPrDeploymentsPublicEnabled');
    expect(file_get_contents(app_path('Livewire/Project/Application/Advanced.php')))
        ->not->toContain('is_preview_deployments_enabled', 'is_pr_deployments_public_enabled');
});
