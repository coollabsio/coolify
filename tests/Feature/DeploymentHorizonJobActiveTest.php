<?php

use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Horizon\Contracts\JobRepository;

uses(RefreshDatabase::class);

beforeEach(function () {
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $this->server->settings->update(['dynamic_timeout' => 3600]);

    $this->deployment = ApplicationDeploymentQueue::create([
        'application_id' => '1',
        'deployment_uuid' => 'horizon-active-uuid',
        'server_id' => $this->server->id,
        'horizon_job_id' => 'job-1',
    ]);
});

function fakeHorizonJobStatus(?string $status): void
{
    $jobs = $status === null ? collect() : collect([(object) ['id' => 'job-1', 'status' => $status]]);

    $repository = Mockery::mock(JobRepository::class);
    $repository->shouldReceive('getJobs')->andReturn($jobs);
    app()->instance(JobRepository::class, $repository);
}

it('counts a reserved job as active', function () {
    fakeHorizonJobStatus('reserved');

    expect($this->deployment->isHorizonJobActive())->toBeTrue();
});

it('does not count a finished job as active', function (string $status) {
    fakeHorizonJobStatus($status);

    expect($this->deployment->isHorizonJobActive())->toBeFalse();
})->with(['completed', 'failed']);

it('counts a job without a Horizon record as active within the deployment timeout', function () {
    fakeHorizonJobStatus(null);
    $this->travel(59)->minutes();

    expect($this->deployment->isHorizonJobActive())->toBeTrue();
});

it('does not count a job without a Horizon record as active after the deployment timeout', function () {
    fakeHorizonJobStatus(null);
    $this->travel(61)->minutes();

    expect($this->deployment->isHorizonJobActive())->toBeFalse();
});
