<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageSecretManager extends RestActionTool
{
    protected string $name = 'manage_secret_manager';

    protected string $description = 'Show, set, or remove the secret manager sources (Doppler, Infisical, Vault) of an application, service, or database, list the key names of their secrets, and add reference variables. A resource can have several named sources: {{vault.KEY}} reads the `default` source, {{vault.<name>.KEY}} the named one. Pass data.name to work on a named source (default: default). Secret values are never returned. Requires read ability for get, write ability for changes and keys. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'get' => ['method' => 'GET', 'paths' => $this->paths(''), 'ability' => 'read', 'description' => 'the source (data: name), all sources of the resource, and the secret manager tokens of the team that can be linked'],
            'set' => ['method' => 'PATCH', 'paths' => $this->paths(''), 'ability' => 'write', 'description' => 'link a token or change the settings of a source (data: name, integration_token_uuid, settings)'],
            'remove' => ['method' => 'DELETE', 'paths' => $this->paths(''), 'ability' => 'delete', 'confirm' => true, 'description' => 'remove a source (data: name); existing references to it then fail the next deployment'],
            'keys' => ['method' => 'GET', 'paths' => $this->paths('/keys'), 'ability' => 'write', 'description' => 'key names of the remote secrets of a source (data: name)'],
            'add_reference' => ['method' => 'POST', 'paths' => $this->paths('/references'), 'ability' => 'write', 'description' => 'add a reference variable for one remote key (data: key, name)'],
            'import' => ['method' => 'POST', 'paths' => $this->paths('/import'), 'ability' => 'write', 'description' => 'add a reference variable for every remote key of a source that has no variable yet (data: name)'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function paths(string $suffix): array
    {
        return [
            'application' => "applications/{uuid}/secret-manager{$suffix}",
            'service' => "services/{uuid}/secret-manager{$suffix}",
            'database' => "databases/{uuid}/secret-manager{$suffix}",
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Application, service, or database UUID.',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request fields, same as the REST API: name (every action; the source name, default: default); integration_token_uuid, settings (set); key (add_reference). Settings: Doppler service account project, config; Infisical project_id, environment, secret_path; Vault mount, path.';
    }
}
