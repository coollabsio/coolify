<?php

namespace App\Notifications\ProjectConfig;

class ConfigApprovalNeeded extends ProjectConfigNotification
{
    public const WEBHOOK_EVENT = 'project_config_approval_needed';

    protected function title(): string
    {
        return 'coolify.json waits for approval';
    }

    protected function message(): string
    {
        $changes = collect($this->revision->plan['changes'] ?? []);
        $removals = $changes->where('kind', 'remove')->pluck('key')->implode(', ');

        return $changes->count().' change(s) wait for approval.'.($removals !== '' ? " Removes: {$removals}." : '');
    }

    protected function isError(): bool
    {
        return false;
    }
}
