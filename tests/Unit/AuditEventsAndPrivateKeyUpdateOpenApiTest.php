<?php

function auditAndPrivateKeyOpenApiDocument(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../openapi.json'), true, flags: JSON_THROW_ON_ERROR);
}

test('documents the audit events endpoint', function () {
    $operation = auditAndPrivateKeyOpenApiDocument()['paths']['/audit-events']['get'] ?? null;

    expect($operation)->not->toBeNull()
        ->and($operation['operationId'])->toBe('list-audit-events')
        ->and($operation['security'])->toBe([['bearerAuth' => []]])
        ->and(collect($operation['parameters'])->pluck('name')->all())->toBe(['per_page', 'page', 'search', 'action', 'source'])
        ->and($operation['responses'])->toHaveKeys(['200', '401', '403', '422']);
});

test('documents the private key update on the uuid path', function () {
    $paths = auditAndPrivateKeyOpenApiDocument()['paths'];

    expect($paths['/security/keys'])->not->toHaveKey('patch')
        ->and($paths['/security/keys/{uuid}']['patch']['operationId'])->toBe('update-private-key')
        ->and($paths['/security/keys/{uuid}']['patch']['parameters'][0])->toMatchArray(['name' => 'uuid', 'in' => 'path', 'required' => true]);
});
