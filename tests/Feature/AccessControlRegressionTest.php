<?php

use App\Livewire\Team\AdminView;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

test('user broadcast channel accepts only its own user ID', function () {
    $callback = Broadcast::getChannels()['user.{userId}'];
    $user = User::factory()->create();

    expect($callback($user, (int) $user->id))->toBeTrue()
        ->and($callback($user, (int) $user->id + 1))->toBeFalse();
});

test('team admin view checks instance admin access when it renders', function () {
    $rootTeam = Team::factory()->create(['id' => 0]);
    $rootUser = User::factory()->create();
    $rootTeam->members()->attach($rootUser->id, ['role' => 'owner']);
    $this->actingAs($rootUser);
    session(['currentTeam' => $rootTeam]);

    Livewire::test(AdminView::class)->assertOk();

    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherTeam->members()->attach($otherUser->id, ['role' => 'admin']);
    $this->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);

    expect(fn () => (new AdminView)->render())->toThrow(HttpException::class);
});
