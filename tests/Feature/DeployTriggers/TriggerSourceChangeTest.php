<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->application = deployTriggerApplication(deployTriggerFixture(), ['base_directory' => '/apps/web'])->refresh();
});

it('clears trigger state when the repository or branch changes', function (string $field, string $value) {
    $this->application->forceFill([
        'trigger_base_commit' => str_repeat('a', 40), 'trigger_set' => ['display' => []],
    ])->save();

    $this->application->update([$field => $value]);

    expect($this->application->refresh()->trigger_base_commit)->toBeNull()
        ->and($this->application->trigger_set)->toBeNull();
})->with([['git_repository', 'acme/other'], ['git_branch', 'next']]);

it('keeps trigger state for unrelated changes', function () {
    $this->application->forceFill(['trigger_base_commit' => str_repeat('a', 40)])->save();

    $this->application->update(['name' => 'renamed']);

    expect($this->application->refresh()->trigger_base_commit)->toBe(str_repeat('a', 40));
});
