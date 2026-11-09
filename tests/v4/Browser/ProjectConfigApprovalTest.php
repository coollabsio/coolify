<?php

use App\Enums\ConfigRevisionStatus;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    $this->stack = seedBrowserResourceStack(['projectUuid' => 'project-config']);
    InstanceSettings::query()->update(['disable_two_step_confirmation' => true]);
    $link = EnvironmentConfigLink::factory()->create([
        'environment_id' => $this->stack['environment']->id, 'mode' => 'git', 'repository' => 'acme/api', 'branch' => 'main',
    ]);
    $this->revision = EnvironmentConfigRevision::factory()->create([
        'link_id' => $link->id, 'status' => ConfigRevisionStatus::WaitingApproval, 'commit_sha' => str_repeat('c', 40),
        'plan' => ['changes' => [
            ['kind' => 'remove', 'key' => 'worker', 'path' => '', 'destructive' => true, 'secret' => false, 'from' => 'application', 'to' => null],
        ], 'errors' => [], 'warnings' => [], 'missing_required' => []],
    ]);
    Cache::flush();
});

it('approves a destructive plan with data deletion', function () {
    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Projects');

    $page->navigate("/project/project-config/environment/{$this->stack['environment']->uuid}/configuration")
        ->assertSee('Waiting for approval')
        ->assertSee('destructive')
        ->check('input[value="worker"]')
        ->click('button:visible:has-text("Approve plan")')
        ->wait(1)
        ->screenshot(filename: 'project-config-approval-modal');

    $page->click('button:visible:has-text("Continue")')
        ->wait(1)
        ->fill('input[type="password"]:visible', 'password')
        ->click('button:visible:has-text("Confirm")')
        ->wait(2)
        ->screenshot(filename: 'project-config-approved');

    expect($this->revision->fresh()->approved_at)->not->toBeNull()
        ->and($this->revision->fresh()->delete_data)->toBe(['worker']);
});
