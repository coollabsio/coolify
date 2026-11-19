<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class RollbackApplication extends RestActionTool
{
    protected string $name = 'rollback_application';

    protected string $description = 'Queue a rollback deployment of an application of the authenticated team to a previous image tag (see list_rollback_images). Requires deploy ability. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'rollback' => ['method' => 'POST', 'path' => 'applications/{uuid}/rollback', 'ability' => 'deploy', 'confirm' => true, 'description' => 'roll back to an image tag (data: commit)'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Application UUID.',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API: commit (image tag / commit SHA from list_rollback_images).';
    }
}
