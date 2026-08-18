<?php

use App\Livewire\Project\Application\Domains;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $keyId,
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
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://example.com,https://www.example.com',
        'redirect' => 'www',
    ]);

    // Factories return the model without the column defaults the database filled in.
    $this->application->refresh();
});

describe('Application redirect permanence', function () {
    test('applications default to temporary redirects', function () {
        expect($this->application->isRedirectPermanent())->toBeFalse();

        $labels = generateLabelsApplication($this->application);

        expect($labels)->toContain('traefik.http.middlewares.0-'.$this->application->uuid.'-to-www.redirectregex.permanent=false')
            ->and($labels)->toContain('caddy_0.redir=https://www.example.com{uri}');
    });

    test('updateRedirectPermanent persists the flag to the application settings', function () {
        Livewire::test(Domains::class, ['application' => $this->application])
            ->assertSuccessful()
            ->set('redirectPermanent', true)
            ->call('updateRedirectPermanent')
            ->assertDispatched('success');

        expect($this->application->fresh()->isRedirectPermanent())->toBeTrue();
    });

    test('enabling permanence flips the generated proxy labels to 301', function () {
        $this->application->settings->update(['is_redirect_permanent' => true]);
        $this->application->refresh();

        $labels = generateLabelsApplication($this->application);

        expect($labels)->toContain('traefik.http.middlewares.0-'.$this->application->uuid.'-to-www.redirectregex.permanent=true')
            ->and($labels)->toContain('caddy_0.redir=https://www.example.com{uri} permanent');
    });

    test('the flag round-trips back into the component state', function () {
        $this->application->settings->update(['is_redirect_permanent' => true]);

        Livewire::test(Domains::class, ['application' => $this->application->fresh()])
            ->assertSuccessful()
            ->assertSet('redirectPermanent', true);
    });

    test('members cannot change the redirect type', function () {
        $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
        $this->actingAs($this->user->fresh());

        Livewire::test(Domains::class, ['application' => $this->application->fresh()])
            ->set('redirectPermanent', true)
            ->call('updateRedirectPermanent')
            ->assertForbidden();

        expect($this->application->fresh()->isRedirectPermanent())->toBeFalse();
    });
});
