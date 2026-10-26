<?php

use App\Actions\Application\StopApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lorisleiva\Actions\Decorators\JobDecorator;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');

    $this->stack = seedBrowserResourceStack();
    $this->application = createBrowserApplication($this->stack, [
        'uuid' => 'app-browser-stop-modal',
        'name' => 'Stop Modal App',
        'status' => 'running:healthy',
    ]);
    $this->url = applicationConfigurationUrl($this->stack['project'], $this->stack['environment'], $this->application);
});

const OPEN_STOP_DIALOG = <<<'JS'
    () => {
        document.getElementById('application-mobile-stop-trigger').click();

        return true;
    }
    JS;

const CLOSE_DIALOG = <<<'JS'
    () => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        return true;
    }
    JS;

const CLEANUP_CHECKBOX = 'input[id^="docker_cleanup-"]';

it('toggles the checkbox even when its DOM state differs from the selected actions', function () {
    loginAndSkipBoarding();

    visit($this->url)
        ->assertSee('Stop Modal App')
        ->assertScript(OPEN_STOP_DIALOG)
        ->wait(0.5)
        ->assertScript('() => { const el = document.querySelector(\''.CLEANUP_CHECKBOX.'\'); el.checked = false; return Alpine.$data(el).selectedActions.includes("docker_cleanup"); }')
        ->click(CLEANUP_CHECKBOX)
        ->wait(0.3)
        ->assertScript('() => document.querySelector(\''.CLEANUP_CHECKBOX.'\').checked', true)
        ->click(CLEANUP_CHECKBOX)
        ->wait(0.3)
        ->assertScript('() => document.querySelector(\''.CLEANUP_CHECKBOX.'\').checked', false)
        ->screenshot(filename: 'modal-confirmation-checkbox-toggle');
});

it('stops with the cleanup option that the dialog shows after it was closed and opened again', function () {
    Queue::fake();
    loginAndSkipBoarding();

    visit($this->url)
        ->assertSee('Stop Modal App')
        ->assertScript(OPEN_STOP_DIALOG)
        ->wait(0.5)
        ->click(CLEANUP_CHECKBOX)
        ->wait(0.3)
        ->assertScript(CLOSE_DIALOG)
        ->wait(1)
        ->assertScript(OPEN_STOP_DIALOG)
        ->wait(0.5)
        ->assertScript('() => document.querySelector(\''.CLEANUP_CHECKBOX.'\').checked', true)
        ->click('Continue')
        ->wait(0.3)
        ->click('Confirm')
        ->wait(1.5)
        ->screenshot(filename: 'modal-confirmation-checkbox-stop');

    Queue::assertPushed(JobDecorator::class, fn (JobDecorator $job) => $job->getAction() instanceof StopApplication
        && $job->getParameters()[2] === true);
});
