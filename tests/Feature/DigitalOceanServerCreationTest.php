<?php

use App\Livewire\Server\New\ByDigitalOcean;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
    ]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('renders only the full width buy button at the bottom of the DigitalOcean form', function () {
    Livewire::test(ByDigitalOcean::class)
        ->set('current_step', 2)
        ->assertDontSee('wire:click="previousStep"', false)
        ->assertSeeHtml('class="button w-full"')
        ->assertSee('Buy & Create Server', false);
});
