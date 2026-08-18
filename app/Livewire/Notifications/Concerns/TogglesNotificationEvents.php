<?php

namespace App\Livewire\Notifications\Concerns;

trait TogglesNotificationEvents
{
    private const NOTIFICATION_EVENT_KEYS = [
        'deploymentSuccess',
        'deploymentFailure',
        'statusChange',
        'backupSuccess',
        'backupFailure',
        'scheduledTaskSuccess',
        'scheduledTaskFailure',
        'dockerCleanupSuccess',
        'dockerCleanupFailure',
        'serverDiskUsage',
        'serverReachable',
        'serverUnreachable',
        'serverPatch',
        'traefikOutdated',
    ];

    public function toggleEvent(string $property): void
    {
        $channel = class_basename(static::class);
        $allowedProperties = array_map(
            static fn (string $event): string => $event.$channel.'Notifications',
            self::NOTIFICATION_EVENT_KEYS,
        );

        abort_unless(in_array($property, $allowedProperties, true), 404);

        $this->{$property} = ! $this->{$property};
        $this->saveModel();
    }
}
