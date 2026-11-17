<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use phpseclib3\Crypt\EC;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $privateKey = PrivateKey::create([
        'name' => 'test-key',
        'private_key' => EC::createKey('Ed25519')->toString('OpenSSH'),
        'team_id' => $this->team->id,
    ]);
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    ServerSetting::create(['server_id' => $server->id, 'wildcard_domain' => 'http://127.0.0.1.sslip.io']);
    $destination = StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'test-network-'.fake()->uuid()]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  node:\n    image: nginx\n",
        'docker_compose_domains' => json_encode(['node' => ['domain' => 'wss://rpc.example.com:8546']]),
    ]);
});

function rewriteWebsocketSchemesMigration(): void
{
    (require database_path('migrations/2026_11_15_150000_rewrite_websocket_schemes_of_domains.php'))->up();
}

test('rewrites saved ws and wss domains so the Compose parser accepts them', function () {
    expect(fn () => applicationParser($this->application))->toThrow(InvalidArgumentException::class);

    rewriteWebsocketSchemesMigration();

    $application = $this->application->fresh();
    expect(json_decode($application->docker_compose_domains, true))
        ->toBe(['node' => ['domain' => 'https://rpc.example.com:8546']]);

    applicationParser($application);

    expect($application->environment_variables()->where('key', 'SERVICE_URL_NODE')->value('value'))
        ->toBe('https://rpc.example.com');
});

test('rewrites every ws and wss domain of a list and keeps other domains', function () {
    DB::table('applications')->where('id', $this->application->id)->update([
        'fqdn' => 'WSS://a.example.com, ws://b.example.com:3000,https://news.example.com/ws://x',
        'docker_compose_domains' => json_encode([
            'node' => ['domain' => 'ws://c.example.com,https://d.example.com', 'redirect' => 'www'],
            'web' => 'wss://e.example.com',
        ]),
    ]);

    rewriteWebsocketSchemesMigration();

    $application = $this->application->fresh();
    expect($application->fqdn)->toBe('https://a.example.com, http://b.example.com:3000,https://news.example.com/ws://x')
        ->and(json_decode($application->docker_compose_domains, true))->toBe([
            'node' => ['domain' => 'http://c.example.com,https://d.example.com', 'redirect' => 'www'],
            'web' => 'https://e.example.com',
        ]);
});
