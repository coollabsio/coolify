<?php

use App\Livewire\Storage\Create;
use App\Livewire\Storage\Form;
use App\Rules\ValidS3BucketName;

function storageRulesFor(string $componentClass): array
{
    $component = new $componentClass;
    $method = new ReflectionMethod($component, 'rules');
    $method->setAccessible(true);

    return $method->invoke($component);
}

it('uses the shared S3 bucket rule in storage create and edit forms', function (string $componentClass) {
    $bucketRules = storageRulesFor($componentClass)['bucket'];

    expect($bucketRules)->toContain('required')
        ->and(collect($bucketRules)->contains(fn ($rule) => $rule instanceof ValidS3BucketName))->toBeTrue();
})->with([
    'create form' => [Create::class],
    'edit form' => [Form::class],
]);
