<?php

use App\Models\Application;
use App\Models\EnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    $this->stack = seedBrowserResourceStack();
    $this->application = createBrowserApplication($this->stack, [
        'uuid' => 'app-browser-env-refresh',
        'name' => 'Env Refresh App',
        'build_pack' => 'dockerfile',
        'dockerfile' => "FROM nginx:alpine\n",
    ]);

    foreach (['ALPHA_KEY', 'BRAVO_KEY', 'CHARLIE_KEY', 'DELTA_KEY'] as $key) {
        EnvironmentVariable::create([
            'key' => $key,
            'value' => strtolower($key),
            'resourceable_type' => Application::class,
            'resourceable_id' => $this->application->id,
        ]);
    }
});

it('locks and deletes environment variables repeatedly without Livewire errors', function () {
    loginAndSkipBoarding();

    $url = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    ).'/environment-variables';

    $page = visit($url);
    $page->click('Accept and close')
        ->assertSee('ALPHA_KEY')
        ->assertSee('DELTA_KEY');

    // Collect uncaught errors (e.g. "Snapshot missing on Livewire component") raised by the actions below.
    $page->script(<<<'JS'
        (() => {
            window.__envListErrors = [];
            window.addEventListener('error', (event) => window.__envListErrors.push(String(event.message)));
            window.addEventListener('unhandledrejection', (event) => window.__envListErrors.push(String(event.reason)));
            const consoleError = console.error;
            console.error = (...args) => {
                window.__envListErrors.push(args.map(String).join(' '));
                consoleError(...args);
            };
        })()
    JS);

    $page->click('button[title="ALPHA_KEY"]')
        ->click('[data-environment-variable-update-actions] button:has-text("Lock"):visible')
        ->wait(1);
    $page->script('window.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }))');
    $page->wait(0.5);

    foreach (['BRAVO_KEY', 'CHARLIE_KEY', 'DELTA_KEY'] as $key) {
        $page->click("button[title=\"{$key}\"]")
            ->click('[data-environment-variable-delete-action] button:visible')
            ->fill('[x-model="userConfirmationText"]:visible', $key)
            ->click('button:has([x-text="step2ButtonText"]):visible')
            ->assertSee('Environment variable deleted successfully.')
            ->assertDontSee($key);
    }

    $page->assertSee('ALPHA_KEY')
        ->screenshot(filename: 'environment-variable-list-refresh');

    expect($page->script('window.__envListErrors'))->toBe([])
        ->and($this->application->environment_variables()->pluck('key')->all())->toBe(['ALPHA_KEY'])
        ->and($this->application->environment_variables()->where('key', 'ALPHA_KEY')->value('is_shown_once'))->toBeTruthy();
});
