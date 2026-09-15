<?php

use App\Actions\Server\StartSentinel;
use App\Events\SentinelRestarted;
use App\Livewire\Server\Sentinel;
use App\Livewire\Server\Show;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $key->id,
        'user' => 'deploy',
    ]);
    $this->server->settings->updateQuietly([
        'is_reachable' => true,
        'is_usable' => true,
        'sentinel_custom_url' => 'https://coolify.example.com',
    ]);
    $this->server->refresh();
    $this->server->sentinelHeartbeat(isReset: true);
    config(['app.maintenance.store' => 'array', 'constants.ssh.max_retries' => 1]);
    Queue::fake();
    Event::fake([SentinelRestarted::class]);
});

it('prefers the instance FQDN for localhost without depending on a published host port', function (string $fqdn) {
    $this->server->updateQuietly(['ip' => 'host.docker.internal']);
    InstanceSettings::query()->whereKey(0)->update(['fqdn' => $fqdn]);
    Once::flush();

    expect($this->server->settings->generateSentinelUrl(save: false))->toBe($fqdn);
})->with(['https://coolify.example.com', 'https://coolify.example.com:8443', 'http://coolify.example.com']);

it('uses the configured host port for localhost without an FQDN', function (int $port) {
    $this->server->updateQuietly(['ip' => 'host.docker.internal']);
    config(['app.port' => $port]);

    expect($this->server->settings->generateSentinelUrl(save: false))
        ->toBe('http://host.docker.internal:'.$port);
})->with([8000, 8001]);

it('repairs the persisted legacy localhost default during sync', function () {
    $this->server->updateQuietly(['ip' => 'host.docker.internal']);
    $this->server->settings->updateQuietly(['sentinel_custom_url' => 'http://host.docker.internal:8000']);
    InstanceSettings::query()->whereKey(0)->update(['fqdn' => 'https://coolify.example.com']);
    Once::flush();

    expect($this->server->settings->ensureSentinelUrl())->toBe('https://coolify.example.com')
        ->and($this->server->settings->fresh()->sentinel_custom_url)->toBe('https://coolify.example.com');
    Queue::assertNothingPushed();
});

it('repairs the legacy localhost default when the host port changes', function () {
    $this->server->updateQuietly(['ip' => 'host.docker.internal']);
    $this->server->settings->updateQuietly(['sentinel_custom_url' => 'http://host.docker.internal:8000']);
    config(['app.port' => 9000]);

    expect($this->server->settings->ensureSentinelUrl())->toBe('http://host.docker.internal:9000');
});

it('preserves remote endpoint generation', function (?string $fqdn, ?string $ipv4, string $expected) {
    InstanceSettings::query()->whereKey(0)->update(['fqdn' => $fqdn, 'public_ipv4' => $ipv4]);
    Once::flush();

    expect($this->server->settings->generateSentinelUrl(save: false))->toBe($expected);
})->with([
    ['https://coolify.example.com', '192.0.2.20', 'https://coolify.example.com'],
    [null, '192.0.2.20', 'http://192.0.2.20:8000'],
]);

it('recognizes the instance server by its zero ID', function () {
    $server = new Server;
    $server->id = 0;
    $server->ip = '192.0.2.20';
    $this->server->settings->setRelation('server', $server);
    config(['app.port' => 9000]);

    expect($this->server->settings->generateSentinelUrl(save: false))->toBe('http://host.docker.internal:9000');
});

it('preserves explicit custom endpoints', function (string $ip, string $url) {
    $this->server->updateQuietly(['ip' => $ip]);
    $this->server->settings->updateQuietly(['sentinel_custom_url' => $url]);
    InstanceSettings::query()->whereKey(0)->update(['fqdn' => 'https://instance.example.com']);
    Once::flush();

    expect($this->server->settings->ensureSentinelUrl())->toBe($url);
})->with([
    ['host.docker.internal', 'https://custom.example.com:9443'],
    ['host.docker.internal', 'http://host.docker.internal:9000'],
    ['192.0.2.10', 'https://custom.example.com'],
    ['192.0.2.10', 'http://host.docker.internal:8000'],
]);

it('does not consider a reset heartbeat live', function () {
    expect($this->server->fresh()->isSentinelLive())->toBeFalse();
});

it('does not manufacture a heartbeat on startup or restart', function (bool $restart) {
    $this->server->sentinelHeartbeat();
    Process::fake(['*' => Process::result(output: 'OK')]);

    StartSentinel::run($this->server, restart: $restart, latestVersion: '0.0.22');

    expect($this->server->fresh()->isSentinelLive())->toBeFalse();
    Event::assertDispatched(SentinelRestarted::class);
    Process::assertRan(fn ($process) => str_contains($process->command, 'sudo docker exec coolify-sentinel curl')
        && str_contains($process->command, '--connect-timeout 5 --max-time 10')
        && str_contains($process->command, 'https://coolify.example.com/api/health')
        && ! str_contains($process->command, '--insecure'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'PUSH_ENDPOINT=https://coolify.example.com')
        && str_contains($process->command, 'TOKEN='.$this->server->settings->sentinel_token));
})->with([false, true]);

it('reports unreachable or incorrect endpoints without a restart success event', function (?string $response) {
    $this->server->forceFill(['sentinel_updated_at' => now()->subHour()])->saveQuietly();
    Process::fake(fn ($process) => str_contains($process->command, 'docker exec coolify-sentinel curl')
        ? Process::result(output: $response ?? '', exitCode: $response === null ? 7 : 0)
        : Process::result(output: 'container-id'));

    expect(fn () => StartSentinel::run($this->server, latestVersion: '0.0.22'))
        ->toThrow(RuntimeException::class, 'Check the Coolify URL in Sentinel settings');

    expect($this->server->fresh()->isSentinelLive())->toBeFalse();
    Event::assertNotDispatched(SentinelRestarted::class);
})->with([null, '<html>Sign in</html>']);

it('becomes live only after an authenticated valid push and expires normally', function () {
    expect($this->server->isSentinelLive())->toBeFalse();

    $this->postJson('/api/v1/sentinel/push', ['containers' => []], [
        'Authorization' => 'Bearer '.$this->server->settings->sentinel_token,
    ])->assertOk();

    expect($this->server->fresh()->isSentinelLive())->toBeTrue();
    $this->travel($this->server->waitBeforeDoingSshCheck() + 1)->seconds();
    expect($this->server->fresh()->isSentinelLive())->toBeFalse();
});

it('does not update health for invalid credentials or invalid push data', function (string $tokenType, array $payload, int $status) {
    $heartbeat = $this->server->fresh()->sentinel_updated_at;
    $token = match ($tokenType) {
        'valid' => $this->server->settings->sentinel_token,
        'stale' => encrypt(json_encode(['server_uuid' => $this->server->uuid])),
        default => 'invalid',
    };

    $this->postJson('/api/v1/sentinel/push', $payload, ['Authorization' => 'Bearer '.$token])
        ->assertStatus($status);

    expect($this->server->fresh()->sentinel_updated_at)->toBe($heartbeat)
        ->and($this->server->fresh()->isSentinelLive())->toBeFalse();
})->with([
    ['invalid', ['containers' => []], 401],
    ['stale', ['containers' => []], 401],
    ['valid', [], 422],
]);

it('shows a sync validation error to an administrator', function () {
    $user = User::factory()->create();
    $user->teams()->attach($this->server->team_id, ['role' => 'admin']);
    session(['currentTeam' => $this->server->team]);
    $this->actingAs($user);
    StartSentinel::shouldRun()->once()->andThrow(new RuntimeException('Check the Coolify URL in Sentinel settings.'));

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->call('restartSentinel')
        ->assertDispatched('error', 'Check the Coolify URL in Sentinel settings.')
        ->assertNotDispatched('success');
});

it('surfaces sync errors from the server overview', function () {
    $user = User::factory()->create();
    $user->teams()->attach($this->server->team_id, ['role' => 'admin']);
    $this->actingAs($user);
    StartSentinel::shouldRun()->once()->andThrow(new RuntimeException('Endpoint unreachable.'));
    $component = new Show;
    $component->server = $this->server;

    expect($component->restartSentinel()->serialize())->toMatchArray([
        'name' => 'error',
        'params' => ['Endpoint unreachable.'],
    ]);
});

it('refreshes resolved URLs after sync without overwriting unsaved edits', function (string $componentClass, bool $edited) {
    $user = User::factory()->create();
    $user->teams()->attach($this->server->team_id, ['role' => 'admin']);
    $this->actingAs($user);
    StartSentinel::shouldRun()->once()->andReturnUsing(function (Server $server) {
        $server->settings->updateQuietly(['sentinel_custom_url' => 'https://resolved.example.com']);
    });
    $component = new $componentClass;
    $component->server = $this->server;
    $component->sentinelCustomUrl = $edited ? 'https://unsaved.example.com' : $this->server->settings->sentinel_custom_url;

    $component->restartSentinel();

    expect($component->sentinelCustomUrl)->toBe($edited ? 'https://unsaved.example.com' : 'https://resolved.example.com')
        ->and($this->server->fresh()->isSentinelLive())->toBeFalse();
})->with([[Sentinel::class], [Show::class]])->with([false, true]);

it('does not allow members or other teams to sync Sentinel', function (string $componentClass, bool $sameTeam) {
    $user = User::factory()->create();
    if ($sameTeam) {
        $user->teams()->attach($this->server->team_id, ['role' => 'member']);
    }
    $this->actingAs($user);
    StartSentinel::shouldNotRun();

    $component = new $componentClass;
    $component->server = $this->server;
    $component->restartSentinel();
    Queue::assertNothingPushed();
})->with([[Sentinel::class], [Show::class]])->with([false, true]);
