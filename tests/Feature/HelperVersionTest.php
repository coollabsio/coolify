<?php

use App\Jobs\CheckHelperImageJob;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    config([
        'app.env' => 'production',
        'constants.coolify.helper_version' => '1.0.17',
        'constants.coolify.versions_url' => 'https://cdn.example.com/coolify/versions.json',
    ]);
});

it('uses a newer helper version after fetching it from the CDN', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://cdn.example.com/coolify/versions.json' => Http::response([
            'coolify' => ['helper' => ['version' => '1.0.18']],
        ]),
    ]);

    (new CheckHelperImageJob)->handle();

    expect(InstanceSettings::findOrFail(0)->helper_version)->toBe('1.0.18')
        ->and(getHelperVersion())->toBe('1.0.18');
});

it('does not use a stored helper version older than the bundled version', function () {
    InstanceSettings::findOrFail(0)->update(['helper_version' => '1.0.16']);

    expect(getHelperVersion())->toBe('1.0.17');
});
