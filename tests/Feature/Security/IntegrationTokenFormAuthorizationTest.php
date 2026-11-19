<?php

use App\Livewire\Security\IntegrationTokenForm;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    if (! InstanceSettings::query()->whereKey(0)->exists()) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }
    Once::flush();
    Http::preventStrayRequests();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'admin']);

    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);
});

function openIntegrationTokenFormAsAdmin(): mixed
{
    return Livewire::test(IntegrationTokenForm::class)
        ->set('provider', 'cloudflare')
        ->set('name', 'review-fix-e-token')
        ->set('token', 'cloudflare-token')
        ->set('capabilities', ['dns']);
}

test('a user demoted to member after opening the form cannot add a token', function () {
    $component = openIntegrationTokenFormAsAdmin();

    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->user->load('teams');

    $component->call('addToken')->assertForbidden();

    expect(IntegrationToken::query()->count())->toBe(0);
});

test('a user who switched to a team where they are a member cannot add a token', function () {
    $memberTeam = Team::factory()->create();
    $memberTeam->members()->attach($this->user->id, ['role' => 'member']);
    $this->user->load('teams');
    $component = openIntegrationTokenFormAsAdmin();

    session(['currentTeam' => $memberTeam]);

    $component->call('addToken')->assertForbidden();

    expect(IntegrationToken::query()->count())->toBe(0);
});
