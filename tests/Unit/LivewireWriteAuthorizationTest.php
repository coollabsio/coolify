<?php

use Illuminate\Support\Str;

test('public Livewire write methods authorize in their own bodies', function (string $path, string $method) {
    $source = file_get_contents(__DIR__."/../../app/Livewire/{$path}.php");
    $methodBody = Str::of($source)
        ->after("public function {$method}(")
        ->after('{')
        ->before("\n    }")
        ->value();

    $authorizationChecks = ['authorize(', '->can(', '->cannot(', 'Gate::', 'isInstanceAdmin(', 'abort_unless(', 'abort_if('];

    expect(collect($authorizationChecks)->contains(
        fn (string $authorizationCheck): bool => str_contains($methodBody, $authorizationCheck)
    ))->toBeTrue();
})->with([
    ['Security/CloudProviderTokenForm', 'addToken'],
    ['Server/ValidateAndInstall', 'init'],
    ['Server/ValidateAndInstall', 'validateOS'],
    ['Server/ValidateAndInstall', 'validatePrerequisites'],
    ['Server/ValidateAndInstall', 'validateDockerEngine'],
    ['Server/ValidateAndInstall', 'validateDockerVersion'],
    ['Project/Shared/GetLogs', 'instantSave'],
    ['Project/Database/Heading', 'activityFinished'],
    ['Security/CloudInitScripts', 'loadScripts'],
    ['Project/Application/General', 'resetDefaultLabels'],
    ['Project/Shared/EnvironmentVariable/Show', 'loadValues'],
]);
