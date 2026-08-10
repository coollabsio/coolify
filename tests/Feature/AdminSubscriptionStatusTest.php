<?php

use App\Livewire\Admin\Index as AdminIndex;
use App\Models\InstanceSettings;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admin search only shows users with paid subscriptions as active', function () {
    config()->set('cache.default', 'array');
    config()->set('constants.coolify.self_hosted', false);

    InstanceSettings::unguarded(
        fn () => InstanceSettings::query()->firstOrCreate(['id' => 0])
    );

    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $rootUser = User::find(0) ?? User::factory()->create(['id' => 0]);
    $rootTeam->members()->syncWithoutDetaching([
        $rootUser->id => ['role' => 'admin'],
    ]);

    $inactiveUser = User::factory()->create(['email' => 'inactive@example.com']);
    $inactiveTeam = Team::factory()->create();
    $inactiveTeam->members()->attach($inactiveUser->id, ['role' => 'owner']);
    Subscription::create([
        'team_id' => $inactiveTeam->id,
        'stripe_subscription_id' => 'sub_stale',
        'stripe_invoice_paid' => false,
    ]);

    $this->actingAs($rootUser);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(AdminIndex::class)
        ->set([
            'foundUsers' => collect(),
            'search' => 'inactive@example.com',
        ])
        ->call('submitSearch')
        ->assertSee('No')
        ->assertDontSee('Yes');
});
