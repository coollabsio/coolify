<?php

use App\Models\ApplicationPreview;
use App\Models\InstanceSettings;
use App\Services\DeployTriggers\DeploymentTriggerScan;
use App\Services\DeployTriggers\HelperCapability;
use App\Services\DeployTriggers\TriggerFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fixture = deployTriggerFixture();
    $this->application = deployTriggerApplication($this->fixture, ['base_directory' => '/apps/web'])->refresh();
    $this->mock(HelperCapability::class, fn ($mock) => $mock->shouldReceive('compatible')->andReturn(true));
    $this->commands = [];
});

function scanOutput(array $overrides = []): string
{
    return json_encode(array_merge([
        'schema_version' => 1, 'commit' => str_repeat('c', 40), 'reference_paths' => ['content/blog'],
        'evidence' => [['from' => 'apps/web/next.config.mjs', 'to' => 'content/blog']], 'widened' => [], 'timings_ms' => ['total' => 3],
    ], $overrides));
}

it('runs the scan in the deployment checkout and stores the result with the fingerprint', function () {
    app(DeploymentTriggerScan::class)->run($this->application, null, '/artifacts/dep', str_repeat('c', 40), function (string $command) {
        $this->commands[] = $command;

        return scanOutput();
    });

    $scan = $this->application->refresh()->trigger_scan;
    expect($this->commands)->toHaveCount(1)
        ->and($this->commands[0])->toContain('coolify-affected scan')
        ->and(base64_decode(str($this->commands[0])->after('printf %s ')->before(' |')->trim("'")->value()))->toContain('"worktree":"/artifacts/dep"')
        ->and($scan['commit'])->toBe(str_repeat('c', 40))
        ->and($scan['reference_paths'])->toBe(['content/blog'])
        ->and($scan['config_fingerprint'])->toBe(TriggerFingerprint::for($this->application));
});

it('does not fail when the scan fails or prints garbage', function (Closure $execute) {
    app(DeploymentTriggerScan::class)->run($this->application, null, '/artifacts/dep', str_repeat('c', 40), $execute);

    expect($this->application->refresh()->trigger_scan)->toBeNull();
})->with([
    'exception' => [fn () => fn (string $command) => throw new RuntimeException('exit 3')],
    'garbage' => [fn () => fn (string $command) => 'not json'],
]);

it('stores a preview scan on the preview', function () {
    $preview = ApplicationPreview::create(['application_id' => $this->application->id, 'pull_request_id' => 4, 'pull_request_html_url' => 'https://example.com/4']);

    app(DeploymentTriggerScan::class)->run($this->application, $preview, '/artifacts/dep', str_repeat('c', 40), fn (string $command) => scanOutput());

    expect($preview->refresh()->trigger_scan['reference_paths'])->toBe(['content/blog'])
        ->and($this->application->refresh()->trigger_scan)->toBeNull();
});

it('skips the scan for fork previews, root apps, and incompatible helpers', function (string $case) {
    $application = $this->application;
    $preview = null;
    if ($case === 'fork') {
        $preview = ApplicationPreview::create(['application_id' => $application->id, 'pull_request_id' => 5, 'pull_request_html_url' => 'https://example.com/5']);
        $preview->forceFill(['is_fork_pull_request' => true])->save();
    }
    if ($case === 'root') {
        $application = deployTriggerApplication(deployTriggerFixture())->refresh();
    }
    if ($case === 'helper') {
        $this->mock(HelperCapability::class, fn ($mock) => $mock->shouldReceive('compatible')->andReturn(false));
    }

    app(DeploymentTriggerScan::class)->run($application, $preview, '/artifacts/dep', str_repeat('c', 40), function (string $command) {
        $this->commands[] = $command;

        return scanOutput();
    });

    expect($this->commands)->toBe([]);
})->with(['fork', 'root', 'helper']);

it('clears trigger state when the repository or branch changes', function (string $field, string $value) {
    $this->application->forceFill([
        'trigger_base_commit' => str_repeat('a', 40), 'trigger_set' => ['display' => []], 'trigger_scan' => ['commit' => str_repeat('a', 40)],
    ])->save();

    $this->application->update([$field => $value]);

    expect($this->application->refresh()->trigger_base_commit)->toBeNull()
        ->and($this->application->trigger_set)->toBeNull()
        ->and($this->application->trigger_scan)->toBeNull();
})->with([['git_repository', 'acme/other'], ['git_branch', 'next']]);

it('keeps trigger state for unrelated changes', function () {
    $this->application->forceFill(['trigger_base_commit' => str_repeat('a', 40)])->save();

    $this->application->update(['name' => 'renamed']);

    expect($this->application->refresh()->trigger_base_commit)->toBe(str_repeat('a', 40));
});

it('skips the scan when smart triggers are off for the application or the instance', function (string $case) {
    if ($case === 'app') {
        $this->application->settings->update(['is_smart_triggers_enabled' => false]);
    } else {
        InstanceSettings::get()->update(['is_smart_triggers_enabled' => false]);
    }

    app(DeploymentTriggerScan::class)->run($this->application->refresh(), null, '/artifacts/dep', str_repeat('c', 40), function (string $command) {
        $this->commands[] = $command;

        return scanOutput();
    });

    expect($this->commands)->toBe([]);
})->with(['app', 'instance']);
