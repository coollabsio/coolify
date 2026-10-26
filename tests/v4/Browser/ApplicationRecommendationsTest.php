<?php

use App\Models\Application;
use App\Models\EnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies and dismisses recommended settings and updates the menu count', function () {
    config()->set('app.maintenance.store', 'array');
    $stack = seedBrowserResourceStack();
    $application = createBrowserApplication($stack, ['docker_registry_image_name' => 'ghcr.io/acme/app']);
    $application->settings->update(['use_build_secrets' => false]);
    EnvironmentVariable::create([
        'key' => 'API_TOKEN',
        'value' => 'secret',
        'is_buildtime' => true,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard')
        ->navigate(route('project.application.recommendations', [
            'project_uuid' => $stack['project']->uuid,
            'environment_uuid' => $stack['environment']->uuid,
            'application_uuid' => $application->uuid,
        ]))
        ->assertSee('Use build secrets')
        ->assertSee('Switch from Nixpacks to Railpack')
        ->assertSee('ghcr.io/acme/app');

    $menuCount = <<<'JS'
        () => document.querySelector('[aria-label$=" open"]')?.textContent.trim() ?? null
    JS;
    $clickButton = fn (string $text) => $page->script(<<<JS
        () => [...document.querySelectorAll('#recommended-settings-section button')]
            .find(button => button.textContent.trim() === '{$text}')
            .click()
    JS);

    expect($page->script($menuCount))->toBe('2');
    $page->screenshot(filename: 'recommendations-open');

    $clickButton('Turn on build secrets');
    $page->wait(1.5);
    expect($application->fresh()->settings->use_build_secrets)->toBeTrue()
        ->and($page->script($menuCount))->toBe('1');

    $clickButton('Dismiss');
    $page->wait(1.5);
    $page->assertSee('No recommendations')->assertSee('Restore');
    expect($page->script($menuCount))->toBeNull();

    $page->assertNoJavaScriptErrors();
    $page->screenshot(filename: 'recommendations-empty');
});
