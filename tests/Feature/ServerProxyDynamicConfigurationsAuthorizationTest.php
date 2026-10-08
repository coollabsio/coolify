<?php

use App\Enums\ProxyTypes;
use App\Livewire\Server\Proxy\DynamicConfigurations;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '203.0.113.20',
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value, 'status' => 'running'],
    ]);
    $this->server->settings->forceFill(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false])->save();

    Process::fake(function ($process) {
        if (str_contains($process->command, 'ls -1')) {
            return Process::result(output: "zzfix-auth.yaml\n");
        }
        if (str_contains($process->command, 'zzfix-auth.yaml')) {
            return Process::result(output: "http:\n  middlewares:\n    auth:\n      basicAuth:\n        users:\n          - 'zzfix:\$apr1\$zzfixsecrethash'\n");
        }

        return Process::result();
    });
});

function actingAsDynamicConfigurationsUser(Team $team, string $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return $user;
}

it('does not load dynamic configuration contents for a team member', function () {
    actingAsDynamicConfigurationsUser($this->team, 'member');

    $component = Livewire::withQueryParams(['server_uuid' => $this->server->uuid])
        ->test(DynamicConfigurations::class)
        ->call('initLoadDynamicConfigurations')
        ->assertOk()
        ->assertNotDispatched('error');

    expect($component->get('contents')->filter()->all())->toBe([])
        ->and(json_encode($component->snapshot))->not->toContain('zzfixsecrethash')
        ->and($component->html())->not->toContain('zzfixsecrethash')
        ->and($component->html())->toContain('zzfix-auth.yaml');
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'head -c'));
});

it('loads dynamic configuration contents for a team admin', function () {
    actingAsDynamicConfigurationsUser($this->team, 'admin');

    $component = Livewire::withQueryParams(['server_uuid' => $this->server->uuid])
        ->test(DynamicConfigurations::class)
        ->call('initLoadDynamicConfigurations')
        ->assertNotDispatched('error');

    expect($component->get('contents')->get('zzfix-auth|yaml'))->toContain('zzfixsecrethash')
        ->and($component->html())->toContain('zzfixsecrethash');
});
