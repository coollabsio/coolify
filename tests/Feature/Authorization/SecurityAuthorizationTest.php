<?php

use App\Livewire\Security\CloudProviderTokenForm;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
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
