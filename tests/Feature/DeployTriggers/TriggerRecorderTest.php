<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Services\DeployTriggers\Contract\ApplicationDecision;
use App\Services\DeployTriggers\Contract\ScanResponse;
use App\Services\DeployTriggers\TriggerRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fixture = deployTriggerFixture();
    $this->application = deployTriggerApplication($this->fixture, ['base_directory' => '/apps/web']);
    $this->recorder = app(TriggerRecorder::class);
    $this->skip = new ApplicationDecision('web', 'skip', 'no_match', 'None of the 2 changed files affect this application.', str_repeat('a', 40));
});

function sha(string $char): string
{
    return str_repeat($char, 40);
}

function recorderDeployment($application, array $attributes): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create(array_merge([
        'application_id' => $application->id,
        'deployment_uuid' => new_public_id(),
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'commit' => sha('a'),
    ], $attributes));
}

it('creates a skipped row then folds the next skip into it', function () {
    $first = $this->recorder->recordSkip($this->application, null, $this->skip, sha('b'), sha('a'), true, 'docs: typo');
    $second = $this->recorder->recordSkip($this->application, null, $this->skip, sha('c'), sha('b'), true, 'chore: readme');

    expect($second->id)->toBe($first->id)
        ->and($second->status)->toBe(ApplicationDeploymentStatus::SKIPPED->value)
        ->and($second->skipped_count)->toBe(2)
        ->and($second->first_skipped_commit)->toBe(sha('b'))
        ->and($second->commit)->toBe(sha('c'))
        ->and($second->commit_message)->toBe('chore: readme')
        ->and($second->trigger_reason['reason_code'])->toBe('no_match')
        ->and($second->finished_at)->not->toBeNull()
        ->and(ApplicationDeploymentQueue::query()->where('application_id', $this->application->id)->count())->toBe(1);
});

it('starts a new skipped row after a real deployment', function () {
    $first = $this->recorder->recordSkip($this->application, null, $this->skip, sha('b'), sha('a'), true, null);
    recorderDeployment($this->application, ['commit' => sha('c')]);

    $second = $this->recorder->recordSkip($this->application, null, $this->skip, sha('d'), sha('c'), true, null);

    expect($second->id)->not->toBe($first->id)
        ->and($second->skipped_count)->toBe(1);
});

it('caps skipped row logs at 20 entries', function () {
    $base = sha('a');
    foreach (range(1, 25) as $index) {
        $after = substr(str_repeat(dechex($index % 16), 40), 0, 40);
        $row = $this->recorder->recordSkip($this->application, null, $this->skip, $after, $base, false, null);
    }

    expect(json_decode($row->refresh()->logs, true))->toHaveCount(20)
        ->and($row->skipped_count)->toBe(25);
});

it('moves the base with compare-and-set', function () {
    $this->application->forceFill(['trigger_base_commit' => sha('a')])->save();

    $this->recorder->recordSkip($this->application, null, $this->skip, sha('b'), sha('a'), true, null);

    expect($this->application->refresh()->trigger_base_commit)->toBe(sha('b'));
});

it('does not move the base when it changed meanwhile', function () {
    $this->application->forceFill(['trigger_base_commit' => sha('e')])->save();

    $this->recorder->recordSkip($this->application, null, $this->skip, sha('b'), sha('a'), true, null);

    expect($this->application->refresh()->trigger_base_commit)->toBe(sha('e'));
});

it('does not move the base when a deployment was in flight', function () {
    $this->application->forceFill(['trigger_base_commit' => sha('a')])->save();

    $this->recorder->recordSkip($this->application, null, $this->skip, sha('b'), sha('a'), false, null);

    expect($this->application->refresh()->trigger_base_commit)->toBe(sha('a'));
});

it('advances the scan commit with the base only when it matched', function () {
    $this->application->forceFill(['trigger_base_commit' => sha('a'), 'trigger_scan' => ['commit' => sha('a'), 'reference_paths' => ['content']]])->save();
    $this->recorder->recordSkip($this->application, null, $this->skip, sha('b'), sha('a'), true, null);
    expect($this->application->refresh()->trigger_scan['commit'])->toBe(sha('b'))
        ->and($this->application->trigger_scan['reference_paths'])->toBe(['content']);

    $this->application->forceFill(['trigger_scan' => ['commit' => sha('9'), 'reference_paths' => []]])->save();
    $this->recorder->recordSkip($this->application, null, $this->skip, sha('c'), sha('b'), true, null);
    expect($this->application->refresh()->trigger_scan['commit'])->toBe(sha('9'))
        ->and($this->application->trigger_base_commit)->toBe(sha('c'));
});

it('records skips and the base on previews by pull request', function () {
    $preview = ApplicationPreview::create(['application_id' => $this->application->id, 'pull_request_id' => 12, 'pull_request_html_url' => 'https://example.com/pr/12']);
    $preview->forceFill(['trigger_base_commit' => sha('a')])->save();

    $row = $this->recorder->recordSkip($this->application, $preview, $this->skip, sha('b'), sha('a'), true, null);

    expect($row->pull_request_id)->toBe(12)
        ->and($preview->refresh()->trigger_base_commit)->toBe(sha('b'))
        ->and($this->application->refresh()->trigger_base_commit)->toBeNull();
});

it('stores the trigger set with its evaluation metadata', function () {
    $this->recorder->storeTriggerSet($this->application, null, ['display' => ['apps/web/**']], sha('b'), 'fingerprint', 'server unreachable');

    expect($this->application->refresh()->trigger_set)->toMatchArray([
        'display' => ['apps/web/**'],
        'evaluated_commit' => sha('b'),
        'config_fingerprint' => 'fingerprint',
        'last_error' => 'server unreachable',
    ])->and($this->application->trigger_set['evaluated_at'])->not->toBeNull();
});

it('keeps the previous set when an evaluation fails without one', function () {
    $this->application->forceFill(['trigger_set' => ['display' => ['apps/web/**'], 'evaluated_commit' => sha('a')]])->save();

    $this->recorder->storeTriggerSet($this->application, null, null, sha('b'), 'fingerprint', 'timeout');

    expect($this->application->refresh()->trigger_set['display'])->toBe(['apps/web/**'])
        ->and($this->application->trigger_set['last_error'])->toBe('timeout');
});

it('stores a scan with the fingerprint', function () {
    $this->recorder->storeScan($this->application, null, new ScanResponse(sha('b'), ['content'], [], []), 'fingerprint');

    expect($this->application->refresh()->trigger_scan)->toMatchArray([
        'commit' => sha('b'), 'reference_paths' => ['content'], 'widened' => [], 'config_fingerprint' => 'fingerprint',
    ]);
});

it('moves the base on success', function () {
    $this->recorder->recordSuccessfulDeployment(recorderDeployment($this->application, ['commit' => sha('d')]));

    expect($this->application->refresh()->trigger_base_commit)->toBe(sha('d'));
});

it('moves the base on success only after every destination finished', function () {
    $parent = recorderDeployment($this->application, ['commit' => sha('d')]);
    $childOne = recorderDeployment($this->application, ['commit' => sha('d'), 'parent_deployment_uuid' => $parent->deployment_uuid, 'status' => ApplicationDeploymentStatus::QUEUED->value]);
    $childTwo = recorderDeployment($this->application, ['commit' => sha('d'), 'parent_deployment_uuid' => $parent->deployment_uuid, 'status' => ApplicationDeploymentStatus::QUEUED->value]);

    $this->recorder->recordSuccessfulDeployment($parent);
    expect($this->application->refresh()->trigger_base_commit)->toBeNull();

    $childOne->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);
    $this->recorder->recordSuccessfulDeployment($childOne);
    expect($this->application->refresh()->trigger_base_commit)->toBeNull();

    $childTwo->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);
    $this->recorder->recordSuccessfulDeployment($childTwo);
    expect($this->application->refresh()->trigger_base_commit)->toBe(sha('d'));
});

it('does not move the base for restart-only, failed, or unresolved deployments', function (array $attributes) {
    $this->recorder->recordSuccessfulDeployment(recorderDeployment($this->application, $attributes));

    expect($this->application->refresh()->trigger_base_commit)->toBeNull();
})->with([
    'restart only' => [['restart_only' => true]],
    'failed' => [['status' => ApplicationDeploymentStatus::FAILED->value]],
    'HEAD' => [['commit' => 'HEAD']],
]);

it('moves the base on rollback', function () {
    $this->application->forceFill(['trigger_base_commit' => sha('e')])->save();

    $this->recorder->recordSuccessfulDeployment(recorderDeployment($this->application, ['commit' => sha('1'), 'rollback' => true]));

    expect($this->application->refresh()->trigger_base_commit)->toBe(sha('1'));
});
