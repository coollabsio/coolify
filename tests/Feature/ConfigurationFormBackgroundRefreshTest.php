<?php

use App\Livewire\Project\Application\Configuration as ApplicationConfiguration;
use App\Livewire\Project\Database\Configuration as DatabaseConfiguration;
use App\Livewire\Project\Service\Configuration as ServiceConfiguration;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('keeps editable configuration pages isolated from background service status polling', function () {
    $forbiddenListeners = [
        ApplicationConfiguration::class => [
            "echo-private:team.{$this->team->id},ServiceChecked",
            "echo-private:team.{$this->team->id},ServiceStatusChanged",
        ],
        DatabaseConfiguration::class => [
            "echo-private:team.{$this->team->id},ServiceChecked",
        ],
        ServiceConfiguration::class => [
            "echo-private:team.{$this->team->id},ServiceChecked",
        ],
    ];

    foreach ($forbiddenListeners as $componentClass => $listenerKeys) {
        $listeners = app($componentClass)->getListeners();

        foreach ($listenerKeys as $listenerKey) {
            expect($listeners)->not->toHaveKey($listenerKey);
        }
    }
});
