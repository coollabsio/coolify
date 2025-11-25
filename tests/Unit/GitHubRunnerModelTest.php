<?php

use App\Models\GitHubRunner;
use App\Models\GitHubRunnerSource;
use App\Models\Server;

test('GitHubRunner isRunning returns true for running status', function () {
    $runner = Mockery::mock(GitHubRunner::class)->makePartial();
    $runner->status = 'running';

    expect($runner->isRunning())->toBeTrue();
});

test('GitHubRunner isRunning returns false for other statuses', function () {
    $runner = Mockery::mock(GitHubRunner::class)->makePartial();
    $runner->status = 'completed';

    expect($runner->isRunning())->toBeFalse();
});

test('GitHubRunner isCompleted returns true for completed status', function () {
    $runner = Mockery::mock(GitHubRunner::class)->makePartial();
    $runner->status = 'completed';

    expect($runner->isCompleted())->toBeTrue();
});

test('GitHubRunner isCompleted returns true for failed status', function () {
    $runner = Mockery::mock(GitHubRunner::class)->makePartial();
    $runner->status = 'failed';

    expect($runner->isCompleted())->toBeTrue();
});

test('GitHubRunner isCompleted returns false for running status', function () {
    $runner = Mockery::mock(GitHubRunner::class)->makePartial();
    $runner->status = 'running';

    expect($runner->isCompleted())->toBeFalse();
});

test('GitHubRunner markAsRunning updates status and timestamp', function () {
    $runner = Mockery::mock(GitHubRunner::class)->makePartial();
    $runner->shouldReceive('update')->once()->with(Mockery::on(function ($data) {
        return $data['status'] === 'running' && isset($data['started_at']);
    }))->andReturn(true);

    $runner->markAsRunning();
});

test('GitHubRunner markAsCompleted updates status and timestamp', function () {
    $runner = Mockery::mock(GitHubRunner::class)->makePartial();
    $runner->shouldReceive('update')->once()->with(Mockery::on(function ($data) {
        return $data['status'] === 'completed' && isset($data['completed_at']);
    }))->andReturn(true);

    $runner->markAsCompleted();
});

test('GitHubRunner markAsFailed updates status and timestamp', function () {
    $runner = Mockery::mock(GitHubRunner::class)->makePartial();
    $runner->shouldReceive('update')->once()->with(Mockery::on(function ($data) {
        return $data['status'] === 'failed' && isset($data['completed_at']);
    }))->andReturn(true);

    $runner->markAsFailed();
});

test('GitHubRunnerSource getAvailableServers returns servers ordered by load', function () {
    $source = Mockery::mock(GitHubRunnerSource::class)->makePartial();

    $serversRelation = Mockery::mock();
    $serversRelation->shouldReceive('wherePivot')->with('is_active', true)->andReturnSelf();
    $serversRelation->shouldReceive('withCount')->andReturnSelf();
    $serversRelation->shouldReceive('orderBy')->with('runners_count', 'asc')->andReturnSelf();
    $serversRelation->shouldReceive('get')->andReturn(collect([
        (object) ['id' => 1, 'runners_count' => 0],
        (object) ['id' => 2, 'runners_count' => 2],
    ]));

    $source->shouldReceive('servers')->andReturn($serversRelation);

    $result = $source->getAvailableServers();

    expect($result)->toHaveCount(2)
        ->and($result->first()->id)->toBe(1);
});

test('GitHubRunnerSource getWebhookUrl returns correct URL', function () {
    $source = Mockery::mock(GitHubRunnerSource::class)->makePartial();
    $source->id = 123;

    config(['app.url' => 'https://coolify.example.com']);

    expect($source->getWebhookUrl())->toBe('https://coolify.example.com/webhooks/github-runner/123');
});
