<?php

use App\Livewire\Project\Application\General;

it('uses safe docker registry image validation rules in the application general form', function () {
    $component = new General;
    $method = new ReflectionMethod($component, 'rules');
    $rules = $method->invoke($component);

    $validator = validator([
        'dockerRegistryImageName' => 'coolify/poc$(touch /tmp/pwned)',
        'dockerRegistryImageTag' => 'latest$(touch /tmp/pwned)',
    ], [
        'dockerRegistryImageName' => $rules['dockerRegistryImageName'],
        'dockerRegistryImageTag' => $rules['dockerRegistryImageTag'],
    ]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('dockerRegistryImageName'))->toBeTrue()
        ->and($validator->errors()->has('dockerRegistryImageTag'))->toBeTrue();
});
