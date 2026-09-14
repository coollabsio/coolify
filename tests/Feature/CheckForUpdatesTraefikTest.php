<?php

use App\Jobs\CheckForUpdatesJob;
use App\Jobs\CheckTraefikVersionJob;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('checks installed Traefik versions after refreshing available versions', function () {
    Bus::fake();
    Http::fake([
        '*' => Http::response([
            'coolify' => ['v4' => ['version' => '4.0.10']],
            'traefik' => ['v3.7' => '3.7.13'],
        ]),
    ]);
    File::shouldReceive('exists')->andReturn(false);
    File::shouldReceive('put')->once();

    InstanceSettings::forceCreate(['id' => 0]);

    config([
        'app.env' => 'production',
        'constants.coolify.self_hosted' => true,
        'constants.coolify.version' => '4.0.10',
    ]);

    (new CheckForUpdatesJob)->handle();

    Bus::assertDispatched(CheckTraefikVersionJob::class);
});
