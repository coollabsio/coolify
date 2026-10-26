<?php

use App\Actions\Server\StartLogDrain;
use App\Actions\Server\StopLogDrain;
use App\Livewire\Server\LogDrains;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Rules\ValidFluentBitConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const SAFE_CUSTOM_LOG_DRAIN_CONFIG = <<<'CONF'
[SERVICE]
    Flush     5
    Parsers_File  parsers.conf
@SET region=eu
[INPUT]
    Name              forward
[FILTER]
    Name grep
    Match *
    Exclude log 127.0.0.1
[OUTPUT]
    Name  http
    Match *
    Host  logs.example.com
    Port  443
CONF;

const SAFE_CUSTOM_LOG_DRAIN_PARSERS = <<<'CONF'
# Skip empty lines
[PARSER]
    Name        empty_line_skipper
    Format      regex
    Regex       /^(?!\s*$).+/
[MULTILINE_PARSER]
    name          multiline-regex
    type          regex
    rule      "start_state"   "/^\d+/"  "cont"
CONF;

function customLogDrainConfigWith(string $section): string
{
    return "[INPUT]\n    Name forward\n{$section}\n[OUTPUT]\n    Name stdout\n    Match *\n";
}

beforeEach(function () {
    Server::flushIdentityMap();
    config([
        'app.maintenance.store' => 'array',
        'cache.default' => 'array',
        'cache.stores.redis.driver' => 'array',
    ]);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
});

function customLogDrainPatch(string $token, string $serverUuid, array $payload)
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ])->patchJson("/api/v1/servers/{$serverUuid}/log-drains", $payload);
}

describe('ValidFluentBitConfig rule', function () {
    test('accepts normal configs and parser files', function () {
        expect((new ValidFluentBitConfig)->check(SAFE_CUSTOM_LOG_DRAIN_CONFIG))->toBeNull()
            ->and((new ValidFluentBitConfig(parsersOnly: true))->check(SAFE_CUSTOM_LOG_DRAIN_PARSERS))->toBeNull()
            ->and((new ValidFluentBitConfig)->check(null))->toBeNull();
    });

    test('rejects unsupported plugins and directives', function (string $config) {
        expect((new ValidFluentBitConfig)->check($config))->not->toBeNull();
    })->with([
        'lua filter' => customLogDrainConfigWith("[FILTER]\n    Name lua\n    Match *\n    script /parsers.conf\n    call cb"),
        'lua filter, other case' => customLogDrainConfigWith("[filter]\n\tNAME\tLua\n    Match *"),
        'wasm filter' => customLogDrainConfigWith("[FILTER]\n    Name wasm\n    Match *"),
        'exec input' => "[INPUT]\n    Name exec\n",
        'exec_wasi input' => "[INPUT]\n    Name exec_wasi\n",
        'file output' => "[INPUT]\n    Name forward\n[OUTPUT]\n    Name file\n    Path /etc\n",
        'plugin name with protocol' => "[INPUT]\n    Name exec://x\n",
        'plugin name from a variable' => "@SET p=lua\n".customLogDrainConfigWith("[FILTER]\n    Name \${p}\n    Match *"),
        'include directive' => "@INCLUDE /parsers.conf\n",
        'plugins section' => "[PLUGINS]\n    Path /parsers.conf\n",
        'plugins_file key' => "[SERVICE]\n    Plugins_File /parsers.conf\n",
        'malformed section header' => "[FILTER] x\n    Name lua\n",
        'key outside a section' => "Name lua\n",
    ]);

    test('rejects parser files that are not parser declarations', function (string $parsers) {
        expect((new ValidFluentBitConfig(parsersOnly: true))->check($parsers))->not->toBeNull();
    })->with([
        'script code' => "function cb(tag, timestamp, record)\n  return 1, timestamp, record\nend\n",
        'input section' => "[INPUT]\n    Name forward\n",
        'set directive' => "@SET a=b\n[PARSER]\n    Name x\n",
    ]);
});

describe('custom log drain API', function () {
    test('a write-only token cannot change a custom log drain', function (array $payload) {
        Queue::fake();
        $token = $this->user->createToken('write-only', ['write'])->plainTextToken;

        customLogDrainPatch($token, $this->server->uuid, $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'Missing required permissions: deploy');

        $settings = $this->server->settings->fresh();
        expect((bool) $settings->is_logdrain_custom_enabled)->toBeFalse()
            ->and($settings->logdrain_custom_config)->toBeNull();
        Queue::assertNothingPushed();
    })->with([
        'enable with config' => [['is_logdrain_custom_enabled' => true, 'logdrain_custom_config' => SAFE_CUSTOM_LOG_DRAIN_CONFIG]],
        'config only' => [['logdrain_custom_config' => SAFE_CUSTOM_LOG_DRAIN_CONFIG]],
        'parser only' => [['logdrain_custom_config_parser' => SAFE_CUSTOM_LOG_DRAIN_PARSERS]],
    ]);

    test('a write-only token cannot change the built-in log drains either', function () {
        Queue::fake();
        $token = $this->user->createToken('write-only', ['write'])->plainTextToken;

        customLogDrainPatch($token, $this->server->uuid, [
            'logdrain_axiom_dataset_name' => 'api-dataset',
            'logdrain_axiom_api_key' => 'axiom-key-123',
        ])->assertForbidden();

        expect($this->server->settings->fresh()->logdrain_axiom_dataset_name)->toBeNull();
    });

    test('a deploy token can save a safe custom log drain', function () {
        Queue::fake();
        $token = $this->user->createToken('write-deploy', ['write', 'deploy'])->plainTextToken;

        customLogDrainPatch($token, $this->server->uuid, [
            'is_logdrain_custom_enabled' => true,
            'logdrain_custom_config' => SAFE_CUSTOM_LOG_DRAIN_CONFIG,
            'logdrain_custom_config_parser' => SAFE_CUSTOM_LOG_DRAIN_PARSERS,
        ])->assertOk();

        $settings = $this->server->settings->fresh();
        expect((bool) $settings->is_logdrain_custom_enabled)->toBeTrue()
            ->and($settings->logdrain_custom_config)->toBe(SAFE_CUSTOM_LOG_DRAIN_CONFIG);
    });

    test('a deploy token cannot save an unsupported custom log drain', function (array $payload, string $errorField) {
        Queue::fake();
        $token = $this->user->createToken('write-deploy', ['write', 'deploy'])->plainTextToken;

        customLogDrainPatch($token, $this->server->uuid, ['is_logdrain_custom_enabled' => true, ...$payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorField);

        expect((bool) $this->server->settings->fresh()->is_logdrain_custom_enabled)->toBeFalse();
        Queue::assertNothingPushed();
    })->with([
        'lua filter' => [
            ['logdrain_custom_config' => customLogDrainConfigWith("[FILTER]\n    Name lua\n    Match *\n    script /parsers.conf\n    call cb")],
            'logdrain_custom_config',
        ],
        'parser file with script code' => [
            ['logdrain_custom_config' => SAFE_CUSTOM_LOG_DRAIN_CONFIG, 'logdrain_custom_config_parser' => "function cb()\nend\n"],
            'logdrain_custom_config_parser',
        ],
    ]);
});

test('StartLogDrain does not start a stored unsupported custom log drain', function () {
    StopLogDrain::mock()->shouldReceive('handle')->andReturn('stopped');
    $this->server->settings->forceFill([
        'is_logdrain_custom_enabled' => true,
        'logdrain_custom_config' => customLogDrainConfigWith("[FILTER]\n    Name lua\n    Match *\n    script /parsers.conf\n    call cb"),
        'logdrain_custom_config_parser' => SAFE_CUSTOM_LOG_DRAIN_PARSERS,
    ])->save();

    expect(fn () => StartLogDrain::run($this->server->fresh()))
        ->toThrow(Exception::class, 'Custom log drain config is not allowed: line 4 uses the lua plugin.');
});

test('the UI does not enable an unsupported custom log drain', function () {
    $this->actingAs($this->user);
    StartLogDrain::mock()->shouldNotReceive('handle');

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainCustomConfig', customLogDrainConfigWith("[FILTER]\n    Name wasm\n    Match *"))
        ->call('toggleLogDrain', 'custom')
        ->assertDispatched('error')
        ->assertSet('isLogDrainCustomEnabled', false);

    expect((bool) $this->server->settings->fresh()->is_logdrain_custom_enabled)->toBeFalse();
});

test('the UI saves a cleared custom config while the custom log drain is disabled', function () {
    $this->actingAs($this->user);
    $this->server->settings->update(['logdrain_custom_config' => SAFE_CUSTOM_LOG_DRAIN_CONFIG]);

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainCustomConfig', '')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($this->server->settings->fresh()->logdrain_custom_config)->toBeEmpty();
});
