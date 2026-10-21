<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive]
class ManageScheduledTask extends RestActionTool
{
    protected string $name = 'manage_scheduled_task';

    protected string $description = 'Create, update, delete, or run now a scheduled task of an application or service of the authenticated team. Requires write ability; run now and an enabled task also require deploy. Same validation and permissions as the REST API.';

    protected function actions(): array
    {
        return [
            'create' => ['method' => 'POST', 'paths' => [
                'application' => 'applications/{uuid}/scheduled-tasks',
                'service' => 'services/{uuid}/scheduled-tasks',
            ], 'ability' => 'write', 'description' => 'create a scheduled task (data: name, command, frequency, container, timeout, enabled; an enabled task needs deploy ability)'],
            'update' => ['method' => 'PATCH', 'paths' => [
                'application' => 'applications/{uuid}/scheduled-tasks/{task_uuid}',
                'service' => 'services/{uuid}/scheduled-tasks/{task_uuid}',
            ], 'ability' => 'write', 'description' => 'update a scheduled task (data: any of name, command, frequency, container, timeout, enabled; an enabled task needs deploy ability)'],
            'delete' => ['method' => 'DELETE', 'paths' => [
                'application' => 'applications/{uuid}/scheduled-tasks/{task_uuid}',
                'service' => 'services/{uuid}/scheduled-tasks/{task_uuid}',
            ], 'ability' => 'write', 'confirm' => true, 'description' => 'delete a scheduled task'],
            'execute' => ['method' => 'POST', 'paths' => [
                'application' => 'applications/{uuid}/scheduled-tasks/{task_uuid}/execute',
                'service' => 'services/{uuid}/scheduled-tasks/{task_uuid}/execute',
            ], 'ability' => 'deploy', 'confirm' => true, 'description' => 'run the task command now'],
        ];
    }

    protected function pathParams(): array
    {
        return [
            'uuid' => 'Application or service UUID.',
            'task_uuid' => 'Scheduled task UUID (not needed for create).',
        ];
    }

    protected function dataDescription(): string
    {
        return 'Request body, same fields as the REST API: name, command, frequency (cron or every_minute, hourly, daily, weekly, monthly, yearly), container, timeout (seconds), enabled.';
    }
}
