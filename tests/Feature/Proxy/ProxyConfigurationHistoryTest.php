<?php

use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Livewire\Server\Proxy;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\ProxyConfigurationVersion;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function proxyHistoryConfiguration(string $tag): string
{
    return "services:\n  traefik:\n    container_name: coolify-proxy\n    image: traefik:{$tag}\n    environment:\n      - CF_DNS_API_TOKEN=zzhistory-secret\n";
}

function proxyHistoryServer(Team $team, array $proxy = []): Server
{
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => (PrivateKey::query()->where('team_id', $team->id)->first() ?? PrivateKey::factory()->create(['team_id' => $team->id]))->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value, 'status' => 'running', ...$proxy],
    ]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);

    return $server->refresh();
}

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    Process::fake();
    $this->withoutVite();

    $this->team = Team::factory()->create();
    $this->server = proxyHistoryServer($this->team);
});

it('saves a version only when the configuration changes', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    SaveProxyConfiguration::run($this->server, proxyHistoryConfiguration('v3.5'), 'ui');
    SaveProxyConfiguration::run($this->server, proxyHistoryConfiguration('v3.5'), 'ui');
    SaveProxyConfiguration::run($this->server, proxyHistoryConfiguration('v3.6'), 'reset');

    $versions = $this->server->proxyConfigurationVersions()->get();

    expect($versions)->toHaveCount(2)
        ->and($versions->pluck('source')->all())->toBe(['reset', 'ui'])
        ->and($versions->pluck('user_id')->unique()->all())->toBe([$user->id])
        ->and($versions->first()->configuration)->toBe(proxyHistoryConfiguration('v3.6'))
        ->and($versions->first()->hash)->toBe($this->server->fresh()->proxy->last_saved_settings);
});

it('keeps the configuration saved before the history as the first version', function () {
    $previous = proxyHistoryConfiguration('v3.4');
    $server = proxyHistoryServer($this->team, [
        'last_saved_proxy_configuration' => $previous,
        'last_applied_settings' => ProxyConfigurationVersion::hashOf($previous),
    ]);

    SaveProxyConfiguration::run($server, proxyHistoryConfiguration('v3.5'), 'ui');

    $versions = $server->proxyConfigurationVersions()->get();

    expect($versions)->toHaveCount(2)
        ->and($versions->last()->configuration)->toBe($previous)
        ->and($versions->last()->source)->toBe('system')
        ->and($versions->last()->applied_at)->not->toBeNull()
        ->and($versions->first()->applied_at)->toBeNull();
});

it('keeps the newest versions and the applied version', function () {
    SaveProxyConfiguration::run($this->server, proxyHistoryConfiguration('v1.0'), 'ui');
    $this->server->markProxyConfigurationApplied(proxyHistoryConfiguration('v1.0'));
    $applied = $this->server->proxyConfigurationVersions()->first();

    foreach (range(1, ProxyConfigurationVersion::MAX_VERSIONS + 5) as $number) {
        SaveProxyConfiguration::run($this->server, proxyHistoryConfiguration("v2.{$number}"), 'ui');
    }

    $versions = $this->server->proxyConfigurationVersions()->get();

    expect($versions)->toHaveCount(ProxyConfigurationVersion::MAX_VERSIONS + 1)
        ->and($versions->first()->configuration)->toBe(proxyHistoryConfiguration('v2.25'))
        ->and($versions->pluck('id'))->toContain($applied->id)
        ->and($versions->pluck('configuration'))->not->toContain(proxyHistoryConfiguration('v2.1'));
});

it('marks the matching version as applied when the proxy starts', function () {
    SaveProxyConfiguration::run($this->server, proxyHistoryConfiguration('v3.5'), 'ui');
    SaveProxyConfiguration::run($this->server, proxyHistoryConfiguration('v3.6'), 'ui');

    $this->server->markProxyConfigurationApplied(proxyHistoryConfiguration('v3.6'));

    [$newest, $older] = $this->server->proxyConfigurationVersions()->get()->all();
    expect($newest->applied_at)->not->toBeNull()
        ->and($older->applied_at)->toBeNull();
});

it('encrypts the configuration at rest', function () {
    SaveProxyConfiguration::run($this->server, proxyHistoryConfiguration('v3.5'), 'ui');

    $stored = ProxyConfigurationVersion::query()->toBase()->value('configuration');

    expect($stored)->not->toContain('zzhistory-secret')
        ->and(Crypt::decryptString($stored))->toBe(proxyHistoryConfiguration('v3.5'));
});

function actingAsProxyHistoryUser(Team $team, string $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return $user;
}

it('restores a version as the new current configuration', function () {
    $user = actingAsProxyHistoryUser($this->team, 'admin');
    SaveProxyConfiguration::run($this->server, proxyHistoryConfiguration('v3.5'), 'ui');
    SaveProxyConfiguration::run($this->server, proxyHistoryConfiguration('v3.6'), 'ui');
    $old = $this->server->proxyConfigurationVersions()->get()->last();

    Livewire::test(Proxy::class, ['server' => $this->server])
        ->call('restoreConfigurationVersion', $old->uuid)
        ->assertDispatched('success')
        ->assertSet('proxySettings', proxyHistoryConfiguration('v3.5'));

    $newest = $this->server->proxyConfigurationVersions()->first();
    expect($this->server->fresh()->proxy->last_saved_proxy_configuration)->toBe(proxyHistoryConfiguration('v3.5'))
        ->and($newest->source)->toBe('restore')
        ->and($newest->user_id)->toBe($user->id)
        ->and($this->server->proxyConfigurationVersions()->count())->toBe(3);
});

it('returns a version and the current configuration for the diff', function () {
    actingAsProxyHistoryUser($this->team, 'admin');
    SaveProxyConfiguration::run($this->server, proxyHistoryConfiguration('v3.5'), 'ui');
    SaveProxyConfiguration::run($this->server, proxyHistoryConfiguration('v3.6'), 'ui');
    $old = $this->server->proxyConfigurationVersions()->get()->last();

    Livewire::test(Proxy::class, ['server' => $this->server])
        ->call('loadProxyConfiguration')
        ->assertSee("proxy-version-{$old->uuid}", false)
        ->call('showConfigurationVersionDiff', $old->uuid)
        ->assertReturned(fn (array $diff) => $diff['original'] === proxyHistoryConfiguration('v3.5')
            && $diff['modified'] === proxyHistoryConfiguration('v3.6'));
});

it('denies the history to a team member', function () {
    SaveProxyConfiguration::run($this->server, proxyHistoryConfiguration('v3.5'), 'ui');
    SaveProxyConfiguration::run($this->server, proxyHistoryConfiguration('v3.6'), 'ui');
    $old = $this->server->proxyConfigurationVersions()->get()->last();
    actingAsProxyHistoryUser($this->team, 'member');

    $component = Livewire::test(Proxy::class, ['server' => $this->server]);
    expect($component->instance()->configurationVersions)->toBeEmpty()
        ->and($component->html())->not->toContain($old->uuid);

    $component->call('showConfigurationVersionDiff', $old->uuid)->assertForbidden();
    Livewire::test(Proxy::class, ['server' => $this->server])
        ->call('restoreConfigurationVersion', $old->uuid)
        ->assertForbidden();

    expect($this->server->fresh()->proxy->last_saved_proxy_configuration)->toBe(proxyHistoryConfiguration('v3.6'))
        ->and($this->server->proxyConfigurationVersions()->count())->toBe(2);
});

it('does not open a version of a server in another team', function () {
    $user = actingAsProxyHistoryUser($this->team, 'admin');
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($user->id, ['role' => 'owner']);
    $otherServer = proxyHistoryServer($otherTeam);
    SaveProxyConfiguration::run($otherServer, proxyHistoryConfiguration('v9.9'), 'ui');
    $foreign = $otherServer->proxyConfigurationVersions()->first();
    SaveProxyConfiguration::run($this->server, proxyHistoryConfiguration('v3.6'), 'ui');

    $component = Livewire::test(Proxy::class, ['server' => $this->server]);
    session(['currentTeam' => $otherTeam]);

    expect(fn () => $component->call('showConfigurationVersionDiff', $foreign->uuid))->toThrow(ModelNotFoundException::class);
    expect(fn () => $component->call('restoreConfigurationVersion', $foreign->uuid))->toThrow(ModelNotFoundException::class);

    expect($this->server->fresh()->proxy->last_saved_proxy_configuration)->toBe(proxyHistoryConfiguration('v3.6'))
        ->and($this->server->proxyConfigurationVersions()->count())->toBe(1);
});
