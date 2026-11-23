<?php

use App\Actions\Server\StartSentinel;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.store' => 'array']);
    Server::flushIdentityMap();
    Queue::fake();
    Cache::flush();
    InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://old.example.com']);

    $user = User::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $user->teams()->first()->id]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true]);
});

function pushSentinelForProblemTest(Server $server, string $token)
{
    return test()->postJson('/api/v1/sentinel/push', ['containers' => []], ['Authorization' => 'Bearer '.$token]);
}

it('remembers when Coolify rejects a push from a server that is not functional', function () {
    $this->server->settings->update(['is_reachable' => false]);

    pushSentinelForProblemTest($this->server, $this->server->settings->sentinel_token)->assertUnauthorized();

    expect($this->server->sentinelPushProblem())->toContain('marked unreachable');
});

it('remembers when Coolify rejects a push with an old token', function () {
    $oldToken = $this->server->settings->sentinel_token;
    $this->server->settings->generateSentinelToken(ignoreEvent: true);

    pushSentinelForProblemTest($this->server, $oldToken)->assertUnauthorized();

    expect($this->server->sentinelPushProblem())->toContain('token does not match');
});

it('forgets the problem after the next accepted push', function () {
    $this->server->rememberSentinelPushProblem('Coolify rejected the push: the Sentinel token does not match.');
    $this->server->forceFill(['sentinel_updated_at' => now()->subHour()])->save();

    pushSentinelForProblemTest($this->server, $this->server->settings->sentinel_token)->assertOk();

    expect($this->server->sentinelPushProblem())->toBeNull();
});

it('moves Sentinel to the new instance URL when the instance URL changes', function () {
    $this->server->settings->update(['sentinel_custom_url' => 'https://old.example.com', 'is_sentinel_enabled' => true]);
    Queue::fake();

    $settings = InstanceSettings::get();
    $settings->fqdn = 'https://new.example.com';
    $settings->save();

    expect($this->server->settings->refresh()->sentinel_custom_url)->toBe('https://new.example.com');
    StartSentinel::assertPushed(fn (StartSentinel $action, array $arguments) => $arguments[0]->is($this->server));
});

it('keeps a custom Sentinel URL when the instance URL changes', function () {
    $this->server->settings->update(['sentinel_custom_url' => 'https://custom.example.com']);

    $settings = InstanceSettings::get();
    $settings->fqdn = 'https://new.example.com';
    $settings->save();

    expect($this->server->settings->refresh()->sentinel_custom_url)->toBe('https://custom.example.com');
});
