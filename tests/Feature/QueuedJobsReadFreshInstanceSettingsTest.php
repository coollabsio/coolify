<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reads fresh instance settings in each queued job of a long-running worker', function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_dns_validation_enabled' => false]);

    // The worker process memoizes the settings with once().
    expect((bool) instanceSettings()->is_dns_validation_enabled)->toBeFalse();

    // Another process (the web UI) enables the setting. Its model events do not reach this process.
    DB::table('instance_settings')->where('id', 0)->update(['is_dns_validation_enabled' => true]);

    dispatch(fn () => Cache::put('seen_dns_validation', (bool) instanceSettings()->is_dns_validation_enabled));

    expect(Cache::get('seen_dns_validation'))->toBeTrue();
});
