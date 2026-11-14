<?php

use App\Actions\Server\StartLogDrain;
use App\Enums\LogDrainType;
use App\Livewire\Server\LogDrains;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->first();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

/**
 * @return array{config: string, env: string}
 */
function startAxiomLogDrain(Server $server): array
{
    $commands = new Collection;
    Process::fake(function ($process) use ($commands) {
        $commands->push(remoteProcessScript($process));

        return Process::result();
    });

    StartLogDrain::run($server->fresh());

    $script = $commands->implode("\n");
    preg_match("/echo '([A-Za-z0-9+\\/=]+)' \\| base64 -d \\| tee \\S+\\/fluent-bit\\.conf/", $script, $config);
    preg_match("/echo '([A-Za-z0-9+\\/=]+)' \\| base64 -d \\| tee \\S+\\/\\.env/", $script, $env);

    return ['config' => base64_decode($config[1]), 'env' => base64_decode($env[1])];
}

it('sends logs to the configured Axiom edge deployment', function () {
    $this->server->settings->update([
        'logdrain_type' => LogDrainType::Axiom,
        'logdrain_config' => ['axiom' => ['dataset_name' => 'dataset', 'api_key' => 'key', 'domain' => 'eu-central-1.aws.edge.axiom.co']],
    ]);

    $files = startAxiomLogDrain($this->server);

    expect($files['config'])->toContain('Host            ${AXIOM_DOMAIN}')
        ->and($files['config'])->toContain('URI             /v1/ingest/${AXIOM_DATASET_NAME}')
        ->and($files['config'])->not->toContain('api.axiom.co')
        ->and($files['env'])->toBe("AXIOM_DOMAIN=eu-central-1.aws.edge.axiom.co\nAXIOM_DATASET_NAME=dataset\nAXIOM_API_KEY=key\n");
});

it('uses the US edge deployment when no Axiom domain is set', function () {
    $this->server->settings->update([
        'logdrain_type' => LogDrainType::Axiom,
        'logdrain_config' => ['axiom' => ['dataset_name' => 'dataset', 'api_key' => 'key']],
    ]);

    expect(startAxiomLogDrain($this->server)['env'])->toStartWith("AXIOM_DOMAIN=us-east-1.aws.edge.axiom.co\n");
});

it('shows the US edge deployment as the Axiom domain of new servers', function () {
    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('logDrainConfig.axiom.domain', 'us-east-1.aws.edge.axiom.co');
});

it('enables Axiom with an EU edge domain', function () {
    StartLogDrain::mock()->shouldReceive('handle')->once()->andReturn('ok');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('logDrainConfig.axiom.domain', 'us-east-1.aws.edge.axiom.co')
        ->set('logDrainConfig.axiom.api_key', 'xaat-key')
        ->set('logDrainConfig.axiom.dataset_name', 'dataset')
        ->set('logDrainConfig.axiom.domain', 'eu-central-1.aws.edge.axiom.co')
        ->call('toggleLogDrain', 'axiom')
        ->assertHasNoErrors()
        ->assertSet('logDrainType', 'axiom');

    expect($this->server->settings->fresh()->logDrainConfig(LogDrainType::Axiom)['domain'])->toBe('eu-central-1.aws.edge.axiom.co');
});

it('rejects an Axiom domain that is not a hostname', function (string $domain) {
    StartLogDrain::mock()->shouldNotReceive('handle');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainConfig.axiom.api_key', 'xaat-key')
        ->set('logDrainConfig.axiom.dataset_name', 'dataset')
        ->set('logDrainConfig.axiom.domain', $domain)
        ->call('toggleLogDrain', 'axiom')
        ->assertDispatched('error')
        ->assertHasErrors('logDrainConfig.axiom.domain')
        ->assertSet('logDrainType', null)
        ->assertSet('logDrainConfig.axiom.dataset_name', 'dataset');

    $settings = $this->server->settings->fresh();
    expect($settings->logdrain_type)->toBeNull()
        ->and($settings->logdrain_config)->toBeNull();
})->with([
    'env injection' => ["eu-central-1.aws.edge.axiom.co\nAXIOM_API_KEY=x"],
    'url' => ['https://eu-central-1.aws.edge.axiom.co'],
]);

it('shows a missing API key under its field when Axiom is enabled', function () {
    StartLogDrain::mock()->shouldNotReceive('handle');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('selectedLogDrainType', 'axiom')
        ->set('logDrainConfig.axiom.dataset_name', 'dataset')
        ->call('toggleLogDrain')
        ->assertDispatched('error')
        ->assertHasErrors('logDrainConfig.axiom.api_key')
        ->assertSet('logDrainType', null)
        ->assertSet('logDrainConfig.axiom.dataset_name', 'dataset');

    expect($this->server->settings->fresh()->logdrain_type)->toBeNull();
});
