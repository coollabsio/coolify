<?php

use App\Jobs\CheckHelperImageJob;
use App\Models\InstanceSettings;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    config([
        'app.env' => 'production',
        'constants.coolify.helper_version' => '1.0.17',
        'constants.coolify.versions_url' => 'https://cdn.example.com/coolify/versions.json',
    ]);
});

it('uses a newer helper version from the local versions file without asking the CDN', function () {
    Http::preventStrayRequests();
    Cache::put('coolify:versions:all', ['coolify' => ['helper' => ['version' => '1.0.18']]], 3600);

    (new CheckHelperImageJob)->handle();

    expect(InstanceSettings::findOrFail(0)->helper_version)->toBe('1.0.18')
        ->and(getHelperVersion())->toBe('1.0.18');
});

it('stores the helper version when the update check refreshes versions.json from the CDN', function () {
    File::partialMock()->shouldReceive('put')->once()->with(base_path('versions.json'), Mockery::type('string'));
    config(['constants.coolify.version' => '4.0.0']);
    Http::preventStrayRequests();
    Http::fake([
        'https://cdn.example.com/coolify/versions.json' => Http::response([
            'coolify' => ['v4' => ['version' => '4.0.0'], 'helper' => ['version' => '1.0.19']],
        ]),
    ]);

    refresh_versions_file();

    expect(InstanceSettings::findOrFail(0)->helper_version)->toBe('1.0.19');
});

it('does not use a stored helper version older than the bundled version', function () {
    InstanceSettings::findOrFail(0)->update(['helper_version' => '1.0.16']);

    expect(getHelperVersion())->toBe('1.0.17');
});

it('keeps the stored helper version and does not notify when the CDN is unavailable', function () {
    Team::factory()->create(['id' => 0])->discordNotificationSettings()->update(['discord_enabled' => true]);
    InstanceSettings::findOrFail(0)->update(['helper_version' => '1.0.17']);
    Notification::fake();
    Sleep::fake();
    File::partialMock()->shouldNotReceive('put');
    Http::preventStrayRequests();
    Http::fake([
        'https://cdn.example.com/coolify/versions.json' => Http::response('error code: 1200', 503, ['Retry-After' => '60']),
    ]);

    expect(refresh_versions_file())->toBeNull();

    expect(InstanceSettings::findOrFail(0)->helper_version)->toBe('1.0.17');
    Notification::assertNothingSent();
});
