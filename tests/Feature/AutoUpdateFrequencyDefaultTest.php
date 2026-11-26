<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('gives a new instance a random minute after midnight for auto updates', function () {
    $settings = InstanceSettings::forceCreate(['id' => 0]);

    expect($settings->fresh()->auto_update_frequency)->toMatch('/^([0-9]|[1-5][0-9]) 0 \* \* \*$/');
});

it('keeps an explicit auto update frequency on create', function () {
    $settings = InstanceSettings::forceCreate(['id' => 0, 'auto_update_frequency' => '23 3 * * *']);

    expect($settings->fresh()->auto_update_frequency)->toBe('23 3 * * *');
});

it('moves instances off the old midnight default and keeps custom schedules', function (string $frequency, string $expected) {
    InstanceSettings::forceCreate(['id' => 0, 'auto_update_frequency' => $frequency]);

    (require database_path('migrations/2026_10_15_213357_spread_default_auto_update_frequency.php'))->up();

    expect(InstanceSettings::find(0)->auto_update_frequency)->toMatch($expected);
})->with([
    'old default' => ['0 0 * * *', '/^([0-9]|[1-5][0-9]) 0 \* \* \*$/'],
    'custom' => ['23 3 * * *', '/^23 3 \* \* \*$/'],
]);
