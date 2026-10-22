<?php

use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    Process::fake(['*' => Process::result(output: 'NOK')]);
});

test('the 9router template generates secrets that docker compose reads unchanged from the .env file', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);

    $service = Service::factory()->create([
        'server_id' => $server->id,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => file_get_contents(base_path('templates/compose/9router.yaml')),
    ]);

    $service->parse();

    $secrets = $service->environment_variables()
        ->where('key', 'like', 'SERVICE_PASSWORD%')
        ->pluck('value', 'key');

    // INITIAL_PASSWORD, JWT_SECRET and API_KEY_SECRET; the admin logs in with the shown password.
    expect($secrets)->toHaveCount(3);
    foreach ($secrets as $key => $value) {
        expect($value)->toMatch('/^[A-Za-z0-9]+$/', "{$key} contains characters that compose interpolates");
    }
});
