<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Services\DeployTriggers\EffectiveBase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->application = deployTriggerApplication(deployTriggerFixture(), ['base_directory' => '/apps/web']);
    $this->application->forceFill(['trigger_base_commit' => str_repeat('a', 40)])->save();
});

function inFlight($application, string $status, string $commit, array $attributes = []): void
{
    ApplicationDeploymentQueue::create(array_merge([
        'application_id' => $application->id,
        'deployment_uuid' => new_public_id(),
        'pull_request_id' => 0,
        'status' => $status,
        'commit' => $commit,
    ], $attributes));
}

it('uses the queued deployment commit and marks it not stored', function () {
    inFlight($this->application, ApplicationDeploymentStatus::IN_PROGRESS->value, str_repeat('b', 40));
    inFlight($this->application, ApplicationDeploymentStatus::QUEUED->value, str_repeat('c', 40));

    expect(EffectiveBase::for($this->application, null))->toBe(['base' => str_repeat('c', 40), 'isStored' => false]);
});

it('returns null for a queued HEAD deployment', function () {
    inFlight($this->application, ApplicationDeploymentStatus::QUEUED->value, 'HEAD');

    expect(EffectiveBase::for($this->application, null))->toBe(['base' => null, 'isStored' => false]);
});

it('compares with the last finished deployment while no base is stored', function () {
    $this->application->forceFill(['trigger_base_commit' => null])->save();
    inFlight($this->application, ApplicationDeploymentStatus::FINISHED->value, str_repeat('d', 40));
    inFlight($this->application, ApplicationDeploymentStatus::FAILED->value, str_repeat('e', 40));
    inFlight($this->application, ApplicationDeploymentStatus::SKIPPED->value, str_repeat('f', 40));

    expect(EffectiveBase::for($this->application, null))->toBe(['base' => str_repeat('d', 40), 'isStored' => false]);
});

it('falls back to the stored base', function () {
    inFlight($this->application, ApplicationDeploymentStatus::FINISHED->value, str_repeat('b', 40));
    inFlight($this->application, ApplicationDeploymentStatus::SKIPPED->value, str_repeat('c', 40));

    expect(EffectiveBase::for($this->application, null))->toBe(['base' => str_repeat('a', 40), 'isStored' => true]);
});

it('ignores restart-only deployments', function () {
    inFlight($this->application, ApplicationDeploymentStatus::QUEUED->value, str_repeat('b', 40), ['restart_only' => true]);

    expect(EffectiveBase::for($this->application, null))->toBe(['base' => str_repeat('a', 40), 'isStored' => true]);
});

it('scopes previews by pull request id', function () {
    $preview = ApplicationPreview::create(['application_id' => $this->application->id, 'pull_request_id' => 7, 'pull_request_html_url' => 'https://example.com/7']);
    $preview->forceFill(['trigger_base_commit' => str_repeat('d', 40)])->save();
    inFlight($this->application, ApplicationDeploymentStatus::QUEUED->value, str_repeat('b', 40));
    inFlight($this->application, ApplicationDeploymentStatus::QUEUED->value, str_repeat('e', 40), ['pull_request_id' => 8]);

    expect(EffectiveBase::for($this->application, $preview))->toBe(['base' => str_repeat('d', 40), 'isStored' => true]);
});
