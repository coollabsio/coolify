<?php

use App\Ai\Ui\ApprovalForm;
use App\Ai\Ui\Field;
use App\Ai\Ui\FieldType;

it('serializes a form and reports editable keys', function () {
    $form = new ApprovalForm('Create postgres database', false, [
        new Field(FieldType::Text, 'name', 'Name', 'my-pg', required: true),
        new Field(FieldType::Toggle, 'instant_deploy', 'Deploy immediately', false),
        new Field(FieldType::Locked, 'server', 'Server', 'srv-abc'),
        new Field(FieldType::Note, 'reason', '', 'This provisions a container.'),
    ]);

    expect($form->editableKeys())->toBe(['name', 'instant_deploy'])
        ->and($form->toArray()['title'])->toBe('Create postgres database')
        ->and($form->toArray()['destructive'])->toBeFalse()
        ->and($form->toArray()['fields'][0]['type'])->toBe('text')
        ->and($form->toArray()['fields'][0]['value'])->toBe('my-pg');
});
