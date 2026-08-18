<?php

use App\Livewire\Project\Service\Domains;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        [
            'id' => 0,
            'is_dns_validation_enabled' => false,
        ]
    ));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '203.0.113.10',
    ]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    $this->destination = StandaloneDocker::withoutEvents(function () {
        return StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
    ]);

    $this->webApp = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'service_id' => $this->service->id,
        'name' => 'web',
        'human_name' => 'Web',
        'image' => 'nginx:alpine',
        'fqdn' => 'https://example.com,https://www.example.com',
        'redirect' => 'www',
    ]);

    // Factories return the model without the column defaults the database filled in.
    $this->service->refresh();
});

describe('Service redirect permanence', function () {
    it('defaults to temporary redirects', function () {
        expect($this->service->is_redirect_permanent)->toBeFalse();

        Livewire::test(Domains::class, ['service' => $this->service])
            ->assertSuccessful()
            ->assertSet('redirectPermanent', false);
    });

    it('persists the flag on the service stack', function () {
        Livewire::test(Domains::class, ['service' => $this->service])
            ->assertSuccessful()
            ->set('redirectPermanent', true)
            ->call('updateRedirectPermanent')
            ->assertDispatched('success');

        expect($this->service->fresh()->is_redirect_permanent)->toBeTrue();
    });

    it('reads the stored flag back into the component state', function () {
        $this->service->update(['is_redirect_permanent' => true]);

        Livewire::test(Domains::class, ['service' => $this->service->fresh()])
            ->assertSuccessful()
            ->assertSet('redirectPermanent', true);
    });

    it('prevents members from changing the redirect type', function () {
        $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
        $this->actingAs($this->user->fresh());

        Livewire::test(Domains::class, ['service' => $this->service->fresh()])
            ->set('redirectPermanent', true)
            ->call('updateRedirectPermanent')
            ->assertForbidden();

        expect($this->service->fresh()->is_redirect_permanent)->toBeFalse();
    });
});
