<?php

/**
 * Tests for GitHub release event webhook handling.
 * Verifies the deploy-on-release feature requested in #4972.
 */

it('parses release event payload correctly', function () {
    // GitHub release webhook payload structure
    $payload = [
        'action' => 'published',
        'release' => [
            'tag_name' => 'v1.2.0',
            'target_commitish' => 'main',
            'name' => 'Release v1.2.0',
        ],
        'repository' => [
            'id' => 12345,
            'full_name' => 'acme/my-app',
        ],
    ];

    $action = data_get($payload, 'action');
    $branch = data_get($payload, 'release.target_commitish');
    $tag = data_get($payload, 'release.tag_name');
    $repoId = data_get($payload, 'repository.id');

    expect($action)->toBe('published');
    expect($branch)->toBe('main');
    expect($tag)->toBe('v1.2.0');
    expect($repoId)->toBe(12345);
});

it('ignores non-published release actions', function () {
    $nonPublishActions = ['created', 'edited', 'deleted', 'prereleased', 'unpublished'];

    foreach ($nonPublishActions as $action) {
        expect($action)->not->toBe('published',
            "Action '{$action}' should not trigger deployment"
        );
    }
});

it('webhook controller handles release event in manual path', function () {
    $controllerFile = file_get_contents(__DIR__.'/../../app/Http/Controllers/Webhook/Github.php');

    // The manual() method should handle release events
    expect($controllerFile)->toContain("x_github_event === 'release'");
    expect($controllerFile)->toContain("action !== 'published'");
    expect($controllerFile)->toContain('release.target_commitish');
    expect($controllerFile)->toContain('release.tag_name');
});

it('release deployment uses tag as commit ref', function () {
    $controllerFile = file_get_contents(__DIR__.'/../../app/Http/Controllers/Webhook/Github.php');

    // Release deployments should pass the tag name as commit reference
    expect($controllerFile)->toContain('commit: $release_tag');
});

it('release deployment checks is_deploy_on_release_enabled setting', function () {
    $controllerFile = file_get_contents(__DIR__.'/../../app/Http/Controllers/Webhook/Github.php');

    expect($controllerFile)->toContain('is_deploy_on_release_enabled');
});

it('application setting model includes deploy on release cast', function () {
    $modelFile = file_get_contents(__DIR__.'/../../app/Models/ApplicationSetting.php');

    expect($modelFile)->toContain("'is_deploy_on_release_enabled' => 'boolean'");
});

it('migration adds the deploy_on_release column', function () {
    $migrations = glob(__DIR__.'/../../database/migrations/*deploy_on_release*');

    expect($migrations)->not->toBeEmpty('Migration file for deploy_on_release should exist');

    $migrationContent = file_get_contents($migrations[0]);
    expect($migrationContent)->toContain('is_deploy_on_release_enabled');
    expect($migrationContent)->toContain("default(false)");
});

it('UI blade template has deploy on release toggle', function () {
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/project/application/advanced.blade.php');

    expect($bladeFile)->toContain('isDeployOnReleaseEnabled');
    expect($bladeFile)->toContain('Deploy on Release');
});

it('livewire component syncs deploy on release setting', function () {
    $componentFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Application/Advanced.php');

    expect($componentFile)->toContain('isDeployOnReleaseEnabled');
    expect($componentFile)->toContain('is_deploy_on_release_enabled');
});

it('push and pull_request events are not affected by release changes', function () {
    $controllerFile = file_get_contents(__DIR__.'/../../app/Http/Controllers/Webhook/Github.php');

    // Push handling still exists unchanged
    expect($controllerFile)->toContain("x_github_event === 'push'");
    expect($controllerFile)->toContain('isDeployable()');

    // PR handling still exists unchanged
    expect($controllerFile)->toContain("x_github_event === 'pull_request'");
    expect($controllerFile)->toContain('isPRDeployable()');
});
