<?php

use App\Actions\Server\StartLogDrain;
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
        'is_logdrain_axiom_enabled' => true,
        'logdrain_axiom_dataset_name' => 'dataset',
        'logdrain_axiom_api_key' => 'key',
        'logdrain_axiom_domain' => 'eu-central-1.aws.edge.axiom.co',
    ]);

    $files = startAxiomLogDrain($this->server);

    expect($files['config'])->toContain('Host            ${AXIOM_DOMAIN}')
        ->and($files['config'])->toContain('URI             /v1/ingest/${AXIOM_DATASET_NAME}')
        ->and($files['config'])->not->toContain('api.axiom.co')
        ->and($files['env'])->toBe("AXIOM_DOMAIN=eu-central-1.aws.edge.axiom.co\nAXIOM_DATASET_NAME=dataset\nAXIOM_API_KEY=key\n");
});

it('uses the US edge deployment when no Axiom domain is set', function () {
    $this->server->settings->update([
        'is_logdrain_axiom_enabled' => true,
        'logdrain_axiom_dataset_name' => 'dataset',
        'logdrain_axiom_api_key' => 'key',
        'logdrain_axiom_domain' => null,
    ]);

    expect(startAxiomLogDrain($this->server)['env'])->toStartWith("AXIOM_DOMAIN=us-east-1.aws.edge.axiom.co\n");
});

it('defaults the Axiom domain of new servers to the US edge deployment', function () {
    expect($this->server->settings->fresh()->logdrain_axiom_domain)->toBe('us-east-1.aws.edge.axiom.co');
});

it('enables Axiom with an EU edge domain', function () {
    StartLogDrain::mock()->shouldReceive('handle')->once()->andReturn('ok');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('logDrainAxiomDomain', 'us-east-1.aws.edge.axiom.co')
        ->set('logDrainAxiomApiKey', 'xaat-key')
        ->set('logDrainAxiomDatasetName', 'dataset')
        ->set('logDrainAxiomDomain', 'eu-central-1.aws.edge.axiom.co')
        ->call('toggleLogDrain', 'axiom')
        ->assertHasNoErrors()
        ->assertSet('isLogDrainAxiomEnabled', true);

    expect($this->server->settings->fresh()->logdrain_axiom_domain)->toBe('eu-central-1.aws.edge.axiom.co');
});

it('rejects an Axiom domain that is not a hostname', function (string $domain) {
    StartLogDrain::mock()->shouldNotReceive('handle');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainAxiomApiKey', 'xaat-key')
        ->set('logDrainAxiomDatasetName', 'dataset')
        ->set('logDrainAxiomDomain', $domain)
        ->call('toggleLogDrain', 'axiom')
        ->assertDispatched('error')
        ->assertHasErrors('logDrainAxiomDomain')
        ->assertSet('isLogDrainAxiomEnabled', false)
        ->assertSet('logDrainAxiomDatasetName', 'dataset');

    $settings = $this->server->settings->fresh();
    expect($settings->is_logdrain_axiom_enabled)->toBeFalsy()
        ->and($settings->logdrain_axiom_domain)->toBe('us-east-1.aws.edge.axiom.co');
})->with([
    'env injection' => ["eu-central-1.aws.edge.axiom.co\nAXIOM_API_KEY=x"],
    'url' => ['https://eu-central-1.aws.edge.axiom.co'],
    'empty' => [''],
]);

it('shows a missing API key under its field when Axiom is enabled from the status dropdown', function () {
    StartLogDrain::mock()->shouldNotReceive('handle');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainAxiomDatasetName', 'dataset')
        ->set('isLogDrainAxiomEnabled', true)
        ->call('instantSave')
        ->assertDispatched('error')
        ->assertHasErrors('logDrainAxiomApiKey')
        ->assertSet('isLogDrainAxiomEnabled', false)
        ->assertSet('logDrainAxiomDatasetName', 'dataset');

    expect($this->server->settings->fresh()->is_logdrain_axiom_enabled)->toBeFalsy();
});
