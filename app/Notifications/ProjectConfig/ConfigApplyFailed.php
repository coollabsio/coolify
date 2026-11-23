<?php

namespace App\Notifications\ProjectConfig;

class ConfigApplyFailed extends ProjectConfigNotification
{
    public const WEBHOOK_EVENT = 'project_config_failed';

    protected function title(): string
    {
        return 'coolify.json was not applied';
    }

    protected function message(): string
    {
        return (string) ($this->revision->error ?? 'The apply failed.');
    }

    protected function isError(): bool
    {
        return true;
    }
}
