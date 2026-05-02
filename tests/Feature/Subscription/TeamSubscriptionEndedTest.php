<?php

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('subscriptionEnded does not throw when team has no subscription', function () {
    $team = Team::factory()->create();

    // Should return early without error — no NPE
    $team->subscriptionEnded();

    // If we reach here, no exception was thrown
    expect(true)->toBeTrue();
});
