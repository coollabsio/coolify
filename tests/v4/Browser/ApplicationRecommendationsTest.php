<?php

use App\Models\Application;
use App\Models\EnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows recommendations on the General page and updates the General menu dot', function () {
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
        ->navigate(route('project.application.configuration', [
            'project_uuid' => $stack['project']->uuid,
            'environment_uuid' => $stack['environment']->uuid,
            'application_uuid' => $application->uuid,
        ]))
        ->assertSee('Use build secrets')
        ->assertSee('Switch from Nixpacks to Railpack')
        ->assertSee('ghcr.io/acme/app');

    $menuCount = <<<'JS'
        () => document.querySelector('.application-settings-navigation [aria-label$="recommendation"], .application-settings-navigation [aria-label$="recommendations"]')?.getAttribute('aria-label') ?? null
    JS;
    $clickButton = fn (string $text) => $page->script(<<<JS
        () => [...document.querySelectorAll('#recommendations-section button')]
            .find(button => button.textContent.trim() === '{$text}')
            .click()
    JS);

    expect($page->script($menuCount))->toBe('2 recommendations');
    $page->screenshot(filename: 'recommendations-open');

    $clickButton('Turn on build secrets');
    $page->wait(1.5);
    expect($application->fresh()->settings->use_build_secrets)->toBeTrue()
        ->and($page->script($menuCount))->toBe('1 recommendation');

    $clickButton('Dismiss');
    $page->wait(1.5);
    $page->assertSee('You dismissed every recommendation')->assertSee('Show dismissed (1)');
    expect($page->script($menuCount))->toBeNull();

    $page->assertNoJavaScriptErrors();
    $page->screenshot(filename: 'recommendations-empty');
});
