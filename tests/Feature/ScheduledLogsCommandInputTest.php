<?php

use App\Console\Commands\ViewScheduledLogs;
use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([DecideWhatToDoWithUser::class, CheckForcePasswordReset::class]);
    Once::flush();
    if (! InstanceSettings::find(0)) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->saveQuietly();
    }
});

describe('logs:scheduled --date option', function () {
    test('rejects a malformed date and exits before touching the shell', function () {
        $this->artisan('logs:scheduled', ['--date' => '2025-01-01; touch /tmp/pwn'])
            ->expectsOutputToContain('Invalid date format')
            ->assertExitCode(ViewScheduledLogs::INVALID);

        expect(file_exists('/tmp/pwn'))->toBeFalse();
    });

    test('accepts a well-formed date', function () {
        $this->artisan('logs:scheduled', ['--date' => '2025-01-01'])
            ->assertExitCode(0);
    });
});
