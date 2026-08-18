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
});

describe('Application Redirect', function () {
    test('setRedirect persists the redirect value to the database', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'fqdn' => 'https://example.com,https://www.example.com',
            'redirect' => 'both',
        ]);

        Livewire::test(Domains::class, ['application' => $application])
            ->assertSuccessful()
            ->set('redirect', 'www')
            ->call('setRedirect')
            ->assertDispatched('success');

        $application->refresh();
        expect($application->redirect)->toBe('www');
    });

    test('setRedirect auto-adds missing www domain instead of rejecting', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'fqdn' => 'https://example.com',
            'redirect' => 'both',
        ]);

        Livewire::test(Domains::class, ['application' => $application])
            ->assertSuccessful()
            ->set('redirect', 'www')
            ->call('setRedirect')
            ->assertDispatched('success');

        $application->refresh();
        expect($application->redirect)->toBe('www')
            ->and(explode(',', (string) $application->fqdn))
            ->toContain('https://example.com')
            ->toContain('https://www.example.com');
    });

    test('setRedirect only treats hostname-leading www as www and auto-adds the real pair', function (string $fqdn, string $expectedWww) {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'fqdn' => $fqdn,
            'redirect' => 'both',
        ]);

        Livewire::test(Domains::class, ['application' => $application])
            ->assertSuccessful()
            ->set('redirect', 'www')
            ->call('setRedirect')
            ->assertDispatched('success');

        $application->refresh();
        expect($application->redirect)->toBe('www')
            ->and(explode(',', (string) $application->fqdn))
            ->toContain($fqdn)
            ->toContain($expectedWww);
    })->with([
        'www in path' => ['https://example.com/www.example.com', 'https://www.example.com/www.example.com'],
        'www in unrelated hostname label' => ['https://app.www.example.com', 'https://www.app.www.example.com'],
    ]);

});
