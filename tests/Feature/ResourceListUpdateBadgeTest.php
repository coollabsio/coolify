<?php

use App\Livewire\Project\Resource\Index;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $compose = "services:\n  app:\n    image: nginx:2\n";
    Cache::flush();
    Cache::forever('coolify:service-templates-bundle', [
        'fetched_at' => now()->toIso8601String(),
        'json' => json_encode(['demo' => ['compose' => base64_encode($compose)]]),
    ]);
});

it('marks services with an available template update in the serialized payload', function () {
    $service = Service::factory()->create([
        'service_type' => 'demo',
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'template_reference_hash' => 'stale',
    ]);

    $method = new ReflectionMethod(Index::class, 'toSearchableArray');
    $method->setAccessible(true);
    $payload = $method->invoke(new Index, collect([$service->load('destination.server')]), 'service', 'Service');

    expect($payload[0]['updateAvailable'])->toBeTrue();
});
