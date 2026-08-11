<?php

use App\Livewire\Project\Application\Domains;
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
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

describe('Application noindex domains', function () {
    test('a flag is kept only for a domain the application actually has', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'fqdn' => 'https://prod.example.com,https://staging.example.com',
        ]);

        $application->setNoindexDomains([
            'https://staging.example.com',
            'https://never-configured.example.com',
        ]);
        $application->save();

        expect($application->refresh()->noindexDomains()->all())
            ->toBe(['https://staging.example.com']);
    });

    test('removing a domain prunes its flag', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'fqdn' => 'https://prod.example.com,https://staging.example.com',
            'noindex_domains' => ['https://staging.example.com'],
        ]);

        // The staging domain goes away; its flag must not survive to be silently
        // resurrected if the same domain is added back later.
        $application->fqdn = 'https://prod.example.com';
        $application->save();

        expect($application->refresh()->noindexDomains()->all())->toBeEmpty();
    });

    test('flags survive a save that does not touch the domains', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'fqdn' => 'https://prod.example.com,https://staging.example.com',
            'noindex_domains' => ['https://staging.example.com'],
        ]);

        $application->name = 'renamed';
        $application->save();

        expect($application->refresh()->noindexDomains()->all())
            ->toBe(['https://staging.example.com']);
    });

    test('isDomainNoindexed ignores casing', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'fqdn' => 'https://staging.example.com',
            'noindex_domains' => ['https://staging.example.com'],
        ]);

        expect($application->isDomainNoindexed('https://Staging.Example.COM'))->toBeTrue();
        expect($application->isDomainNoindexed('https://prod.example.com'))->toBeFalse();
    });

    test('the domains view toggle persists the flag', function () {
        InstanceSettings::unguarded(function () {
            InstanceSettings::updateOrCreate(['id' => 0], []);
        });
        $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
        $server = Server::factory()->create([
            'team_id' => $this->team->id,
            'private_key_id' => $privateKey->id,
        ]);
        $destination = StandaloneDocker::where('server_id', $server->id)->first()
            ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);

        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $destination->id,
            'destination_type' => StandaloneDocker::class,
            'fqdn' => 'https://prod.example.com,https://staging.example.com',
            'static_image' => 'nginx:alpine',
            'base_directory' => '/',
            'is_http_basic_auth_enabled' => false,
            'redirect' => 'no',
        ]);

        Livewire::test(Domains::class, ['application' => $application])
            ->assertSuccessful()
            ->call('toggleNoindexDomain', 'https://staging.example.com', true)
            ->assertDispatched('success');

        expect($application->refresh()->noindexDomains()->all())
            ->toBe(['https://staging.example.com']);
    });
});
