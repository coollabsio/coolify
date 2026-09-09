<?php

use App\Models\InstanceSettings;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\ExceptionRenderer;
use Illuminate\Foundation\Exceptions\Renderer\Renderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

test('debug exceptions use laravel renderer instead of ignition', function () {
    config(['app.debug' => true]);

    expect(class_exists('Spatie\\LaravelIgnition\\IgnitionServiceProvider'))->toBeFalse()
        ->and(app()->bound(ExceptionRenderer::class))->toBeFalse()
        ->and(app()->bound(Renderer::class))->toBeTrue();

    $response = app(ExceptionHandler::class)
        ->render(request(), new RuntimeException('default-laravel-exception-renderer'));

    expect($response->getContent())
        ->toContain('default-laravel-exception-renderer')
        ->toContain('scheme-light-dark')
        ->toContain('dark:bg-neutral-900');
});

test('dev exception preview url renders the laravel debug page', function () {
    config(['app.debug' => true]);

    $this->get('/__exception')
        ->assertServerError()
        ->assertSee('RuntimeException', false)
        ->assertSee('Testing Laravel exception page')
        ->assertSee('scheme-light-dark', false);
});
