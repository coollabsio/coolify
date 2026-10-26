<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const NESTED_PROXY_SECRET = 'CF_DNS_API_TOKEN=nested-proxy-config-secret';

function nestedProxyApiToken(User $user, Team $team, array $abilities): string
{
    session(['currentTeam' => $team]);
    $token = $user->createToken('nested-proxy-test', $abilities);
    DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update([
        'team_id' => $team->id,
    ]);

    return $token->plainTextToken;
}

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings;
    $settings->id = 0;
    $settings->save();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->proxy->set('last_saved_proxy_configuration', "services:\n  traefik:\n    environment:\n      - ".NESTED_PROXY_SECRET."\n");
    $this->server->save();

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $destination = $this->server->standaloneDockers()->firstOrFail();

    $this->application = Application::create([
        'name' => 'nested-proxy-app',
        'git_repository' => 'https://github.com/test/test',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->database = StandalonePostgresql::create([
        'name' => 'nested-proxy-db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'app',
        'image' => 'postgres:16-alpine',
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $this->environment->id,
    ]);
});

dataset('endpoints with nested server data', [
    'applications list' => [fn () => '/api/v1/applications'],
    'application detail' => [fn () => "/api/v1/applications/{$this->application->uuid}"],
    'databases list' => [fn () => '/api/v1/databases'],
    'database detail' => [fn () => "/api/v1/databases/{$this->database->uuid}"],
    'services list' => [fn () => '/api/v1/services'],
    'service detail' => [fn () => "/api/v1/services/{$this->service->uuid}"],
    'resources list' => [fn () => '/api/v1/resources'],
]);

test('read token does not receive the saved proxy configuration of a nested server', function (Closure $endpoint) {
    $token = nestedProxyApiToken($this->user, $this->team, ['read']);

    $response = $this->withToken($token)->getJson($endpoint->call($this));

    $response->assertOk();
    expect($response->getContent())
        ->not->toContain('nested-proxy-config-secret')
        ->not->toContain('last_saved_proxy_configuration');
})->with('endpoints with nested server data');

test('read sensitive token still receives the saved proxy configuration of a nested server', function (Closure $endpoint) {
    $token = nestedProxyApiToken($this->user, $this->team, ['read', 'read:sensitive']);

    $response = $this->withToken($token)->getJson($endpoint->call($this));

    $response->assertOk();
    expect($response->getContent())->toContain('nested-proxy-config-secret');
})->with('endpoints with nested server data');
