<?php

use App\Models\ScheduledTask;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack();
    $service = createBrowserService($this->stack, ['uuid' => 'svc-browser-task', 'name' => 'Task Service'])['service'];
    $this->task = ScheduledTask::factory()->create([
        'team_id' => 0,
        'service_id' => $service->id,
        'name' => 'nightly-cleanup',
        'command' => 'echo cleanup',
        'frequency' => 'daily',
    ]);
    $this->base = serviceConfigurationUrl($this->stack['project'], $this->stack['environment'], $service);
});

/**
 * Records the HTTP status of every Livewire request the page sends.
 */
function recordScheduledTaskLivewireStatuses(mixed $page): void
{
    $page->script(<<<'JS'
        (() => {
            window.livewireStatuses = [];
            const fetch = window.fetch;
            window.fetch = async (...args) => {
                const response = await fetch(...args);
                if (String(args[0]).includes('/livewire')) {
                    window.livewireStatuses.push(response.status);
                }
                return response;
            };
        })()
    JS);
}

it('deletes a service scheduled task from its modal and stays on the task list', function () {
    loginAndSkipBoarding();

    $page = visit("{$this->base}/scheduled-tasks?task_uuid={$this->task->uuid}")
        ->assertSee('Task configuration');
    recordScheduledTaskLivewireStatuses($page);

    $page->click('button:visible:has-text("Danger Zone")')
        ->click('button:visible:has-text("Delete task")')
        ->fill('input[x-model="userConfirmationText"]', 'nightly-cleanup')
        ->click('button:visible:has-text("Permanently delete")')
        ->wait(3);

    expect($this->task->fresh())->toBeNull()
        ->and($page->script('window.livewireStatuses'))->not->toContain(404);

    $page->assertPathIs(parse_url("{$this->base}/scheduled-tasks", PHP_URL_PATH))
        ->assertDontSee('Task configuration')
        ->screenshot(filename: 'scheduled-task-deleted');
});

it('keeps polling executions without a 404 after the task is deleted', function () {
    loginAndSkipBoarding();

    $page = visit("{$this->base}/scheduled-tasks?task_uuid={$this->task->uuid}")
        ->assertSee('Task configuration');
    recordScheduledTaskLivewireStatuses($page);

    // The executions list polls every 5 seconds, so a poll can run after the delete and before the modal closes.
    $this->task->delete();
    $page->script(<<<'JS'
        Livewire.all().find((component) => component.name === 'project.shared.scheduled-task.executions')
            .$wire.refreshExecutions()
    JS);
    $page->wait(1);

    expect($page->script('window.livewireStatuses'))->toBe([200]);
    $page->screenshot(filename: 'scheduled-task-poll-after-delete');
});
