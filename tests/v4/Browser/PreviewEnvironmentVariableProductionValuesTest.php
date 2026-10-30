<?php

use App\Models\Application;
use App\Models\EnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    $this->stack = seedBrowserResourceStack();
    $this->application = createBrowserApplication($this->stack, [
        'uuid' => 'app-browser-preview-production-values',
        'name' => 'Preview Values App',
        'build_pack' => 'dockerfile',
    ]);

    foreach (['API_URL' => 'https://api.example.com', 'DB_PASSWORD' => 'production-secret'] as $key => $value) {
        EnvironmentVariable::create([
            'key' => $key,
            'value' => $value,
            'resourceable_type' => Application::class,
            'resourceable_id' => $this->application->id,
        ]);
    }
    $this->application->environment_variables_preview()->where('key', 'API_URL')->first()
        ->update(['value' => 'https://preview-api.example.com']);
});

it('keeps the production value badge after production changes and removes it when the preview value changes', function () {
    loginAndSkipBoarding();

    $url = applicationConfigurationUrl($this->stack['project'], $this->stack['environment'], $this->application);

    $page = visit($url.'/environment-variables');
    $page->click('Accept and close')
        ->assertSee('Preview deployments');

    expect($page->script('document.querySelectorAll(".env-table-item").length'))->toBe(4)
        ->and($page->script('[...document.querySelectorAll(".env-table-item")].map((row) => row.dataset.envKey + ":" + row.textContent.includes("Production value"))'))
        ->toBe(['API_URL:false', 'DB_PASSWORD:false', 'API_URL:false', 'DB_PASSWORD:true']);
    $page->screenshot(filename: 'preview-env-production-value-badge');

    $page->click('button[title="DB_PASSWORD"] >> nth=0')
        ->wait(1)
        ->fill('input[name="value"]:visible', 'rotated-production-secret')
        ->click('[data-environment-variable-update-actions] button[type="submit"]:visible')
        ->assertSee('Environment variable updated.')
        ->wait(1);

    expect($page->script('[...document.querySelectorAll(".env-table-item")].map((row) => row.dataset.envKey + ":" + row.textContent.includes("Production value"))'))
        ->toBe(['API_URL:false', 'DB_PASSWORD:false', 'API_URL:false', 'DB_PASSWORD:true']);

    $page->click('button[title="DB_PASSWORD"] >> nth=1')
        ->wait(1)
        ->fill('input[name="value"]:visible', 'preview-secret')
        ->click('[data-environment-variable-update-actions] button[type="submit"]:visible')
        ->assertSee('Environment variable updated.')
        ->wait(1);

    expect($page->script('[...document.querySelectorAll(".env-table-item")].some((row) => row.textContent.includes("Production value"))'))->toBeFalse();

    $page->navigate($url.'/preview-deployments')
        ->assertSee('Use production values in previews')
        ->click('input[wire\\:model="useProductionValuesInPreviews"]')
        ->assertSee('Settings saved.')
        ->screenshot(filename: 'preview-env-use-production-values-checkbox');

    expect($this->application->fresh()->settings->use_production_values_in_previews)->toBeFalse();
});
