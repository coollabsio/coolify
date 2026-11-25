<?php

use App\Services\DeployTriggers\TriggerFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fixture = deployTriggerFixture();
});

it('narrows applications that use part of a repository', function (array $attributes) {
    expect(deployTriggerApplication($this->fixture, $attributes)->isNarrowable())->toBeTrue();
})->with([
    'base directory' => [['base_directory' => '/apps/web']],
    'dockerfile in a subdirectory' => [['build_pack' => 'dockerfile', 'dockerfile_location' => '/apps/web/Dockerfile']],
    'compose file in a subdirectory' => [['build_pack' => 'dockercompose', 'docker_compose_location' => '/deploy/docker-compose.yaml']],
    'pnpm filter' => [['build_command' => 'pnpm --filter web build']],
    'pnpm short filter' => [['install_command' => 'pnpm install -F web...']],
    'turbo filter' => [['build_command' => 'turbo run build --filter=web']],
    'npm workspace' => [['build_command' => 'npm run build -w apps/web']],
    'yarn workspace' => [['build_command' => 'yarn workspace web build']],
    'nx project' => [['build_command' => 'npx nx build web']],
    'cargo package' => [['build_command' => 'cargo build --release -p api']],
    'go package' => [['build_command' => 'go build -o app ./cmd/api']],
    'gradle project' => [['build_command' => './gradlew :api:bootJar']],
    'maven module' => [['build_command' => 'mvn -pl api package']],
]);

it('narrows an application that shares its repository and branch with another team application', function () {
    $first = deployTriggerApplication($this->fixture, ['git_repository' => 'acme/mono', 'git_branch' => 'main']);
    deployTriggerApplication($this->fixture, ['git_repository' => 'acme/mono', 'git_branch' => 'main']);

    expect($first->isNarrowable())->toBeTrue();
});

it('does not narrow a single application built from the repository root', function () {
    $application = deployTriggerApplication($this->fixture, ['build_command' => 'npm run build']);

    expect($application->isNarrowable())->toBeFalse();
});

it('does not count the same repository in another team', function () {
    $application = deployTriggerApplication($this->fixture, ['git_repository' => 'acme/mono', 'git_branch' => 'main']);
    deployTriggerApplication(deployTriggerFixture(), ['git_repository' => 'acme/mono', 'git_branch' => 'main']);

    expect($application->isNarrowable())->toBeFalse();
});

it('does not count another branch of the same repository', function () {
    $application = deployTriggerApplication($this->fixture, ['git_repository' => 'acme/mono', 'git_branch' => 'main']);
    deployTriggerApplication($this->fixture, ['git_repository' => 'acme/mono', 'git_branch' => 'staging']);

    expect($application->isNarrowable())->toBeFalse();
});

it('changes the fingerprint when a trigger relevant field changes', function (string $field, mixed $value) {
    $application = deployTriggerApplication($this->fixture);
    $before = TriggerFingerprint::for($application);

    $application->{$field} = $value;

    expect(TriggerFingerprint::for($application))->not->toBe($before);
})->with([
    ['build_pack', 'dockerfile'],
    ['base_directory', '/apps/web'],
    ['dockerfile_location', '/apps/web/Dockerfile'],
    ['docker_compose_location', '/x/docker-compose.yaml'],
    ['install_command', 'pnpm i'],
    ['build_command', 'pnpm build'],
    ['start_command', 'node .'],
    ['docker_compose_custom_build_command', 'docker compose build'],
    ['docker_compose_custom_start_command', 'docker compose up'],
    ['watch_paths', 'apps/web/**'],
    ['git_repository', 'acme/other'],
    ['git_branch', 'next'],
]);

it('keeps the fingerprint for unrelated fields', function () {
    $application = deployTriggerApplication($this->fixture);
    $before = TriggerFingerprint::for($application);

    $application->name = 'renamed';

    expect(TriggerFingerprint::for($application))->toBe($before);
});

it('changes the fingerprint when the toggle changes', function () {
    $application = deployTriggerApplication($this->fixture);
    $before = TriggerFingerprint::for($application);

    $application->settings->is_smart_triggers_enabled = false;

    expect(TriggerFingerprint::for($application))->not->toBe($before);
});
