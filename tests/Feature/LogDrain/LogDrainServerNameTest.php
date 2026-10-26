<?php

use App\Actions\Server\StartLogDrain;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::forceCreate(['id' => 0]);
});

test('a server name with a line break cannot add a section to the fluent-bit config', function (array $settings) {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'name' => "prod\n[OUTPUT]\n    Name stdout\r\n    Match *",
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings()->update($settings);

    $commands = new Collection;
    Process::fake(function ($process) use ($commands) {
        $commands->push($process->command);

        return Process::result();
    });

    StartLogDrain::run($server->fresh());

    preg_match("/echo '([A-Za-z0-9+\\/=]+)' \\| base64 -d \\| tee \\S+\\/fluent-bit\\.conf/", $commands->implode("\n"), $matches);
    $config = base64_decode($matches[1]);

    expect($config)->toContain("Set                 coolify.server_name prod-[OUTPUT]-Name-stdout-Match-*\n")
        ->and(preg_match_all('/^\[OUTPUT\]/m', $config))->toBe(1)
        ->and($config)->not->toMatch('/^\s*Name stdout/m');
})->with([
    'new relic' => [['is_logdrain_newrelic_enabled' => true, 'logdrain_newrelic_license_key' => 'key', 'logdrain_newrelic_base_uri' => 'https://log-api.newrelic.com/log/v1']],
    'axiom' => [['is_logdrain_axiom_enabled' => true, 'logdrain_axiom_dataset_name' => 'dataset', 'logdrain_axiom_api_key' => 'key']],
]);

test('a server name with spaces is written as a single fluent-bit value', function (array $settings) {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'name' => 'Test Server #1',
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings()->update($settings);

    $commands = new Collection;
    Process::fake(function ($process) use ($commands) {
        $commands->push($process->command);

        return Process::result();
    });

    StartLogDrain::run($server->fresh());

    preg_match("/echo '([A-Za-z0-9+\\/=]+)' \\| base64 -d \\| tee \\S+\\/fluent-bit\\.conf/", $commands->implode("\n"), $matches);
    $config = base64_decode($matches[1]);

    expect($config)->toContain("Set                 coolify.server_name Test-Server-#1\n");
})->with([
    'new relic' => [['is_logdrain_newrelic_enabled' => true, 'logdrain_newrelic_license_key' => 'key', 'logdrain_newrelic_base_uri' => 'https://log-api.newrelic.com/log/v1']],
    'axiom' => [['is_logdrain_axiom_enabled' => true, 'logdrain_axiom_dataset_name' => 'dataset', 'logdrain_axiom_api_key' => 'key']],
]);
