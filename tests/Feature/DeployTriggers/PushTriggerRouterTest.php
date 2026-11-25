<?php

use App\Jobs\EvaluatePush;
use App\Models\InstanceSettings;
use App\Services\DeployTriggers\PushContext;
use App\Services\DeployTriggers\PushTriggerRouter;
use App\Services\DeployTriggers\TriggerRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->fixture = deployTriggerFixture();
    $this->router = PushTriggerRouter::forPush(new PushContext('gitlab', $this->fixture['team']->id, 'https://gitlab.com/acme/mono', 'refs/heads/main', str_repeat('b', 40)));
});

it('routes by trigger precedence', function (array $attributes, bool $instanceOn, bool $appOn, TriggerRoute $expected) {
    InstanceSettings::get()->update(['is_smart_triggers_enabled' => $instanceOn]);
    $application = deployTriggerApplication($this->fixture, $attributes);
    $application->settings->update(['is_smart_triggers_enabled' => $appOn]);

    expect($this->router->route($application->refresh()))->toBe($expected);
})->with([
    'instance off, no watch paths' => [['base_directory' => '/apps/web'], false, true, TriggerRoute::DeployNow],
    'instance off, watch paths' => [['base_directory' => '/apps/web', 'watch_paths' => 'apps/web/**'], false, true, TriggerRoute::Evaluate],
    'app toggle off, no watch paths' => [['base_directory' => '/apps/web'], true, false, TriggerRoute::DeployNow],
    'not git based' => [['build_pack' => 'dockerimage', 'base_directory' => '/apps/web'], true, true, TriggerRoute::DeployNow],
    'not narrowable' => [[], true, true, TriggerRoute::DeployNow],
    'narrowable' => [['base_directory' => '/apps/web'], true, true, TriggerRoute::Evaluate],
    'watch paths on a root app' => [['watch_paths' => 'src/**'], true, true, TriggerRoute::Evaluate],
]);

it('dispatches one job for all deferred applications', function () {
    $web = deployTriggerApplication($this->fixture, ['base_directory' => '/apps/web']);
    $api = deployTriggerApplication($this->fixture, ['base_directory' => '/apps/api']);

    $this->router->defer($web);
    $this->router->defer($api);
    $this->router->dispatch();

    Queue::assertPushed(EvaluatePush::class, 1);
    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $job) => $job->applicationIds === [$web->id, $api->id]);
});

it('dispatches nothing without deferred applications', function () {
    $this->router->dispatch();

    Queue::assertNotPushed(EvaluatePush::class);
});

it('describes an evaluating application for the webhook response', function () {
    $web = deployTriggerApplication($this->fixture, ['name' => 'web', 'base_directory' => '/apps/web']);

    expect($this->router->responseEntry($web))->toMatchArray(['application' => 'web', 'status' => 'evaluating']);
});

it('dispatches one job per team', function () {
    $mine = deployTriggerApplication($this->fixture, ['base_directory' => '/apps/web']);
    $otherFixture = deployTriggerFixture();
    $theirs = deployTriggerApplication($otherFixture, ['base_directory' => '/apps/web']);

    $this->router->defer($mine);
    $this->router->defer($theirs);
    $this->router->dispatch();

    Queue::assertPushed(EvaluatePush::class, 2);
    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $job) => $job->context->teamId === $otherFixture['team']->id && $job->applicationIds === [$theirs->id]);
});
