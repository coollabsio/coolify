<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageSecretManager extends RestActionTool
{
    protected string $name = 'manage_secret_manager';

    protected string $description = 'Show, set, or remove the secret manager source (Doppler, Infisical, Vault) of an application, service, or database, list the key names of its secrets, and add {{vault.KEY}} reference variables. Secret values are never returned. Requires read ability for get, write ability for changes and keys. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'get' => ['method' => 'GET', 'paths' => $this->paths(''), 'ability' => 'read', 'description' => 'the source and the secret manager tokens of the team that can be linked'],
            'set' => ['method' => 'PATCH', 'paths' => $this->paths(''), 'ability' => 'write', 'description' => 'link a token or change the settings (data: integration_token_uuid, settings)'],
            'remove' => ['method' => 'DELETE', 'paths' => $this->paths(''), 'ability' => 'delete', 'confirm' => true, 'description' => 'remove the source; existing {{vault.*}} variables then fail the next deployment'],
            'keys' => ['method' => 'GET', 'paths' => $this->paths('/keys'), 'ability' => 'write', 'description' => 'key names of the remote secrets'],
            'add_reference' => ['method' => 'POST', 'paths' => $this->paths('/references'), 'ability' => 'write', 'description' => 'add a {{vault.KEY}} variable for one remote key (data: key)'],
            'import' => ['method' => 'POST', 'paths' => $this->paths('/import'), 'ability' => 'write', 'description' => 'add a {{vault.KEY}} variable for every remote key that has no variable yet'],
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
        return 'Request body, same fields as the REST API: integration_token_uuid, settings (set); key (add_reference). Settings: Doppler service account project, config; Infisical project_id, environment, secret_path; Vault mount, path.';
    }
}
