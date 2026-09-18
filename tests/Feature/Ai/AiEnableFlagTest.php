<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

test('instance ai flag defaults off and is boolean-cast', function () {
    $settings = InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    expect($settings->fresh()->is_ai_assistant_enabled)->toBeFalse();
});

test('team ai flag defaults on and is boolean-cast', function () {
    $team = Team::factory()->create();

    expect($team->fresh()->is_ai_assistant_enabled)->toBeTrue();
});
