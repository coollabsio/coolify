<?php

function sharedEnvOpenApiSpec(): array
{
    return json_decode(
        file_get_contents(__DIR__.'/../../openapi.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

it('documents a JSON request body on every shared env create and update endpoint', function (string $path, string $method, string $schemaName) {
    $operation = sharedEnvOpenApiSpec()['paths'][$path][$method];

    expect($operation['requestBody']['required'] ?? null)->toBeTrue()
        ->and($operation['requestBody']['content']['application/json']['schema']['$ref'] ?? null)
        ->toBe("#/components/schemas/{$schemaName}");
})->with([
    ['/team/envs', 'post', 'SharedEnvironmentVariableCreateRequest'],
    ['/team/envs/{env_id}', 'patch', 'SharedEnvironmentVariableUpdateRequest'],
    ['/projects/{uuid}/envs', 'post', 'SharedEnvironmentVariableCreateRequest'],
    ['/projects/{uuid}/envs/{env_id}', 'patch', 'SharedEnvironmentVariableUpdateRequest'],
    ['/projects/{uuid}/environments/{environment_name_or_uuid}/envs', 'post', 'SharedEnvironmentVariableCreateRequest'],
    ['/projects/{uuid}/environments/{environment_name_or_uuid}/envs/{env_id}', 'patch', 'SharedEnvironmentVariableUpdateRequest'],
    ['/servers/{uuid}/envs', 'post', 'SharedEnvironmentVariableCreateRequest'],
    ['/servers/{uuid}/envs/{env_id}', 'patch', 'SharedEnvironmentVariableUpdateRequest'],
]);

it('documents the fields accepted by the shared env validator', function () {
    $schemas = sharedEnvOpenApiSpec()['components']['schemas'];
    $fields = ['key', 'value', 'is_literal', 'is_multiline', 'is_shown_once', 'comment'];

    expect(array_keys($schemas['SharedEnvironmentVariableCreateRequest']['properties']))->toBe($fields)
        ->and($schemas['SharedEnvironmentVariableCreateRequest']['required'])->toBe(['key'])
        ->and(array_keys($schemas['SharedEnvironmentVariableUpdateRequest']['properties']))->toBe($fields)
        ->and($schemas['SharedEnvironmentVariableUpdateRequest'])->not->toHaveKey('required');
});
