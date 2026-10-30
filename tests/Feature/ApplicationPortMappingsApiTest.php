<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'port-mappings-api-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

function patchPortMappings(string $bearerToken, Application $application, ?string $portsMappings)
{
    return test()->withHeaders(['Authorization' => 'Bearer '.$bearerToken])
        ->patchJson("/api/v1/applications/{$application->uuid}", ['ports_mappings' => $portsMappings]);
}

test('accepts every port mapping format that the UI accepts', function (string $portsMappings) {
    patchPortMappings($this->bearerToken, $this->application, $portsMappings)->assertOk();

    expect($this->application->refresh()->ports_mappings)->toBe($portsMappings);
})->with([
    'plain' => '8080:80',
    'udp protocol' => '8080:80/udp',
    'sctp protocol' => '8080:80/sctp',
    'port range' => '8000-8010:8000-8010',
    'ipv4 binding' => '127.0.0.1:8080:80',
    'ipv6 binding with random host port' => '[::1]::80',
    'same port for tcp and udp' => '53:53/tcp,53:53/udp',
    'same port on different host ips' => '127.0.0.1:8080:80,10.0.0.1:8080:80',
    'random host ports on one ip' => '127.0.0.1::80,127.0.0.1::81',
]);

test('clears port mappings with null', function () {
    $this->application->update(['ports_mappings' => '8080:80']);

    patchPortMappings($this->bearerToken, $this->application, null)->assertOk();

    expect($this->application->refresh()->ports_mappings)->toBeNull();
});

test('rejects invalid port mappings', function (string $portsMappings) {
    patchPortMappings($this->bearerToken, $this->application, $portsMappings)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ports_mappings');
})->with([
    'missing container port' => '8080',
    'unknown protocol' => '8080:80/icmp',
    'shell characters' => '8080:80;id',
]);

test('rejects duplicate host bindings', function (string $portsMappings) {
    patchPortMappings($this->bearerToken, $this->application, $portsMappings)
        ->assertUnprocessable()
        ->assertJsonPath('errors.ports_mappings', 'Each host IP, port, and protocol can only be used once.');
})->with([
    'same host port' => '8080:80,8080:81',
    'same host port with explicit tcp' => '8080:80,8080:81/tcp',
    'same ip and host port' => '127.0.0.1:8080:80,127.0.0.1:8080:81',
]);
