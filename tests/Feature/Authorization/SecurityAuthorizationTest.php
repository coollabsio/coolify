<?php

use App\Livewire\Security\CloudInitScripts;
use App\Livewire\Security\CloudProviderTokenForm;
use App\Models\CloudInitScript;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();
    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);
});

test('member cannot create a cloud provider token through the public action', function () {
    Http::fake(['*' => Http::response([], 200)]);

    Livewire::test(CloudProviderTokenForm::class)
        ->set('provider', 'hetzner')
        ->set('token', 'secret-token')
        ->set('name', 'Unauthorized token')
        ->call('addToken')
        ->assertForbidden();

    expect(CloudProviderToken::query()->count())->toBe(0);
});

test('member cannot load cloud-init scripts through the public action', function () {
    $script = CloudInitScript::query()->create([
        'team_id' => $this->team->id,
        'name' => 'Protected script',
        'script' => '#cloud-config',
    ]);

    CloudInitScript::query()->whereKey($script)->update(['uuid' => null]);

    $component = app(CloudInitScripts::class);

    expect(fn () => $component->loadScripts())
        ->toThrow(AuthorizationException::class);

    expect($script->refresh()->uuid)->toBeNull();
});
