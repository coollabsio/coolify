<?php

use App\Livewire\Project\Service\TemplateUpdate;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\TemplateUpdateChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $compose = "services:\n  app:\n    image: nginx:2\n";
    Cache::flush();
    Cache::forever('coolify:service-templates-bundle', [
        'fetched_at' => now()->toIso8601String(),
        'json' => json_encode(['demo' => ['compose' => base64_encode($compose), 'envs' => base64_encode('NEWKEY=hi')]]),
    ]);
});

function makeDemoService(): Service
{
    return Service::factory()->create([
        'service_type' => 'demo',
        'environment_id' => test()->environment->id,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:1\n",
        'compose_parsing_version' => '5',
        'template_reference_hash' => 'stale',
    ]);
}

it('renders update-available state and applies the compose update', function () {
    $service = makeDemoService();

    Livewire::test(TemplateUpdate::class, ['service' => $service])
        ->assertSee('Update available')
        ->set('acceptedHunks', [0 => true])
        ->call('apply');

    $service->refresh();
    expect($service->docker_compose_raw)->toContain('nginx:2');
    expect($service->docker_compose_raw)->not->toContain('nginx:1');
    expect($service->template_reference_hash)->toBe(TemplateUpdateChecker::currentHash('demo'));
    expect($service->template_dismissed_hash)->toBeNull();
});

it('flags the demo service so the page banner and tab dot show', function () {
    $service = makeDemoService();
    expect(TemplateUpdateChecker::showBadge($service->refresh()))->toBeTrue();
});

it('dismisses the current version so the badge is suppressed', function () {
    $service = makeDemoService();

    Livewire::test(TemplateUpdate::class, ['service' => $service])
        ->call('dismiss');

    $service->refresh();
    expect($service->template_dismissed_hash)->toBe(TemplateUpdateChecker::currentHash('demo'));
    expect(TemplateUpdateChecker::showBadge($service))->toBeFalse();
});
