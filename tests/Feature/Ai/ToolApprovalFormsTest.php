<?php

use App\Ai\Contracts\HasApprovalForm;
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
    foreach ([CreateDatabase::class, CreateService::class, CreateProject::class, CreateEnvironment::class,
        UpsertEnvironmentVariable::class, RunServerCommand::class, DeleteResource::class, DeleteServer::class] as $tool) {
        expect(app($tool))->toBeInstanceOf(HasApprovalForm::class);
    }
});
