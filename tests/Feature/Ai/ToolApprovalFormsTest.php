<?php

use App\Ai\Contracts\HasApprovalForm;
use App\Ai\Tools\CreateApplication;
use App\Ai\Tools\CreateDatabase;
use App\Ai\Tools\CreateEnvironment;
use App\Ai\Tools\CreateProject;
use App\Ai\Tools\CreateService;
use App\Ai\Tools\DeleteResource;
use App\Ai\Tools\DeleteServer;
use App\Ai\Tools\RunServerCommand;
use App\Ai\Tools\UpsertEnvironmentVariable;

it('CreateDatabase builds an editable form pre-filled from arguments', function () {
    $form = app(CreateDatabase::class)->approvalForm([
        'type' => 'postgresql', 'name' => 'my-pg',
        'project_uuid' => 'p1', 'environment_name' => 'production',
        'server_uuid' => 's1', 'instant_deploy' => false,
    ]);

    expect($form->editableKeys())->toContain('name')->toContain('type')->toContain('instant_deploy')
        ->and($form->editableKeys())->not->toContain('project_uuid')
        ->and($form->editableKeys())->not->toContain('server_uuid')
        ->and($form->toArray()['fields'][0]['value'])->toBe('my-pg');
});

it('RunServerCommand exposes an editable command and is destructive', function () {
    $form = app(RunServerCommand::class)->approvalForm(['server_uuid' => 's1', 'command' => 'ls /']);

    expect($form->editableKeys())->toBe(['command'])
        ->and($form->destructive)->toBeTrue();
});

it('UpsertEnvironmentVariable exposes editable key and value', function () {
    $form = app(UpsertEnvironmentVariable::class)->approvalForm([
        'resource' => 'application', 'uuid' => 'x1', 'key' => 'FOO', 'value' => 'bar',
    ]);

    expect($form->editableKeys())->toBe(['key', 'value']);
});

it('DeleteResource and DeleteServer are confirm-only and destructive', function () {
    $del = app(DeleteResource::class)->approvalForm(['resource' => 'application', 'uuid' => 'x1']);
    $srv = app(DeleteServer::class)->approvalForm(['server_uuid' => 's1']);

    expect($del->editableKeys())->toBe([])
        ->and($del->destructive)->toBeTrue()
        ->and($srv->editableKeys())->toBe([])
        ->and($srv->destructive)->toBeTrue();
});

it('every approvable create/write tool implements HasApprovalForm', function () {
    foreach ([CreateApplication::class, CreateDatabase::class, CreateService::class, CreateProject::class, CreateEnvironment::class,
        UpsertEnvironmentVariable::class, RunServerCommand::class, DeleteResource::class, DeleteServer::class] as $tool) {
        expect(app($tool))->toBeInstanceOf(HasApprovalForm::class);
    }
});

it('CreateApplication builds a per-type form with the type locked', function (string $type, array $extra, array $editable) {
    $form = app(CreateApplication::class)->approvalForm(array_merge([
        'type' => $type, 'name' => 'x', 'project_uuid' => 'p1', 'environment_name' => 'production', 'server_uuid' => 's1',
    ], $extra));

    expect($form->editableKeys())->toBe($editable)
        ->and(collect($form->toArray()['fields'])->firstWhere('key', 'type')['type'])->toBe('locked');
})->with([
    'public' => ['public', ['git_repository' => 'https://github.com/a/b', 'git_branch' => 'main', 'build_pack' => 'nixpacks'], ['name', 'domains', 'instant_deploy', 'git_repository', 'git_branch', 'build_pack', 'ports_exposes']],
    'private-gh-app' => ['private-gh-app', ['github_app_uuid' => 'gh1', 'git_repository' => 'a/b', 'git_branch' => 'main', 'build_pack' => 'nixpacks'], ['name', 'domains', 'instant_deploy', 'git_repository', 'git_branch', 'build_pack', 'ports_exposes']],
    'private-deploy-key' => ['private-deploy-key', ['private_key_uuid' => 'k1', 'git_repository' => 'git@x:a/b.git', 'git_branch' => 'main', 'build_pack' => 'nixpacks'], ['name', 'domains', 'instant_deploy', 'git_repository', 'git_branch', 'build_pack', 'ports_exposes']],
    'dockerfile' => ['dockerfile', ['dockerfile' => 'FROM nginx'], ['name', 'domains', 'instant_deploy', 'dockerfile']],
    'dockerimage' => ['dockerimage', ['docker_registry_image_name' => 'nginx'], ['name', 'domains', 'instant_deploy', 'docker_registry_image_name', 'docker_registry_image_tag', 'ports_exposes']],
]);
