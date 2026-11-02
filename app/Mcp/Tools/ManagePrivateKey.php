<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManagePrivateKey extends RestActionTool
{
    protected string $name = 'manage_private_key';

    protected string $description = 'List, get, add, update, or delete SSH private keys of the authenticated team. The key material is never returned. Requires read ability for list/get, write ability for changes. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'list' => ['method' => 'GET', 'path' => 'security/keys', 'ability' => 'read', 'description' => 'private keys of the team (no key material)'],
            'get' => ['method' => 'GET', 'path' => 'security/keys/{uuid}', 'ability' => 'read', 'description' => 'one private key (no key material)'],
            'create' => ['method' => 'POST', 'path' => 'security/keys', 'ability' => 'write', 'description' => 'add a private key (data: private_key PEM or base64, name, description)'],
            'update' => ['method' => 'PATCH', 'path' => 'security/keys/{uuid}', 'ability' => 'write', 'description' => 'update a private key (data: name, description; private_key only to replace the key)'],
            'delete' => ['method' => 'DELETE', 'path' => 'security/keys/{uuid}', 'ability' => 'write', 'confirm' => true, 'description' => 'delete a private key that is not in use'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Private key UUID (get, update, delete).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API: private_key, name, description.';
    }
}
