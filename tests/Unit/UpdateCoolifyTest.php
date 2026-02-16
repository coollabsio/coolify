<?php

use App\Actions\Server\UpdateCoolify;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->mockServer = Mockery::mock(Server::class)->makePartial();
    $this->mockServer->id = 0;

    $this->settings = Mockery::mock(InstanceSettings::class)->makePartial();
    $this->settings->is_auto_update_enabled = true;
    $this->settings->shouldReceive('save')->andReturn(true);
});

afterEach(function () {
    Mockery::close();
});

it('has UpdateCoolify action class', function () {
    expect(class_exists(UpdateCoolify::class))->toBeTrue();
});

it('validates cache against running version before fallback', function () {
    $this->app->instance('update_coolify.server', $this->mockServer);
    $this->app->instance(InstanceSettings::class, $this->settings);

    Http::fake(['*' => Http::response(null, 500)]);

    Cache::shouldReceive('remember')
        ->andReturn(['coolify' => ['v4' => ['version' => '4.0.5']]]);

    config(['constants.coolify.version' => '4.0.10']);

    $action = new UpdateCoolify;

    try {
        $action->handle(manual_update: false);
        expect(false)->toBeTrue('Expected exception was not thrown');
    } catch (\Exception $e) {
        expect($e->getMessage())->toContain('cache version');
        expect($e->getMessage())->toContain('4.0.5');
        expect($e->getMessage())->toContain('4.0.10');
    }
});

it('uses validated cache when CDN fails and cache is newer', function () {
    $this->app->instance('update_coolify.server', $this->mockServer);
    $this->app->instance(InstanceSettings::class, $this->settings);
    $this->app->instance('update_coolify.update_runner', function () {});

    Http::fake(['*' => Http::response(null, 500)]);

    Cache::shouldReceive('remember')
        ->andReturn(['coolify' => ['v4' => ['version' => '4.0.10']]]);

    config(['constants.coolify.version' => '4.0.5']);

    $action = new UpdateCoolify;

    \Illuminate\Support\Facades\Log::shouldReceive('warning')
        ->once()
        ->with('Failed to fetch fresh version from CDN, using validated cache', Mockery::type('array'));

    $action->handle(manual_update: false);

    expect($action->latestVersion)->toBe('4.0.10');
});

it('prevents downgrade even with manual update', function () {
    $this->app->instance('update_coolify.server', $this->mockServer);
    $this->app->instance(InstanceSettings::class, $this->settings);

    Http::fake([
        '*' => Http::response([
            'coolify' => ['v4' => ['version' => '4.0.0']],
        ], 200),
    ]);

    config(['constants.coolify.version' => '4.0.10']);

    $action = new UpdateCoolify;

    \Illuminate\Support\Facades\Log::shouldReceive('error')
        ->once()
        ->with('Downgrade prevented', Mockery::type('array'));

    try {
        $action->handle(manual_update: true);
        expect(false)->toBeTrue('Expected exception was not thrown');
    } catch (\Exception $e) {
        expect($e->getMessage())->toContain('Cannot downgrade');
        expect($e->getMessage())->toContain('4.0.10');
        expect($e->getMessage())->toContain('4.0.0');
    }
});
