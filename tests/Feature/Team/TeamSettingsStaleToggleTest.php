<?php

use App\Livewire\Team\Index;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create(['show_boarding' => false, 'is_terminal_api_enabled' => true]);
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
});

test('a stale settings page does not re-enable the terminal api', function () {
    $page = Livewire::test(Index::class);

    // Another administrator disables the Terminal API after the page loaded.
    $this->team->fresh()->update(['is_terminal_api_enabled' => false]);

    $page->set('name', 'Renamed Team')
        ->call('submit')
        ->assertDispatched('success')
        ->assertSet('is_terminal_api_enabled', false);

    $team = $this->team->fresh();
    expect($team->name)->toBe('Renamed Team')
        ->and($team->is_terminal_api_enabled)->toBeFalse();
});

test('an explicit toggle change on the settings page is saved', function (bool $initial) {
    $this->team->update(['is_terminal_api_enabled' => $initial]);

    Livewire::test(Index::class)
        ->set('is_terminal_api_enabled', ! $initial)
        ->call('submit')
        ->assertDispatched('success');

    expect($this->team->fresh()->is_terminal_api_enabled)->toBe(! $initial);
})->with([
    'enable' => false,
    'disable' => true,
]);
