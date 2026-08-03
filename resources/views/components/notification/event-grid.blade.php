@props([
    'settings',
    'channel',
    'threaded' => false,
])

@php
    $eventGroups = [
        'Deployments' => [
            ['key' => 'deploymentSuccess', 'label' => 'Deployment success'],
            ['key' => 'deploymentFailure', 'label' => 'Deployment failure'],
            [
                'key' => 'statusChange',
                'label' => 'Container status changes',
                'helper' => 'Notify when a container stops or restarts.',
            ],
        ],
        'Backups' => [
            ['key' => 'backupSuccess', 'label' => 'Backup success'],
            ['key' => 'backupFailure', 'label' => 'Backup failure'],
        ],
        'Scheduled tasks' => [
            ['key' => 'scheduledTaskSuccess', 'label' => 'Scheduled task success'],
            ['key' => 'scheduledTaskFailure', 'label' => 'Scheduled task failure'],
        ],
        'Servers' => [
            ['key' => 'dockerCleanupSuccess', 'label' => 'Docker cleanup success'],
            ['key' => 'dockerCleanupFailure', 'label' => 'Docker cleanup failure'],
            ['key' => 'serverDiskUsage', 'label' => 'Disk usage warning'],
            ['key' => 'serverReachable', 'label' => 'Server reachable'],
            ['key' => 'serverUnreachable', 'label' => 'Server unreachable'],
            ['key' => 'serverPatch', 'label' => 'Server patching'],
            ['key' => 'traefikOutdated', 'label' => 'Traefik proxy outdated'],
        ],
    ];
@endphp

<x-application.settings-section title="Notification events">
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($eventGroups as $group => $events)
            @php
                $multiselectEvents = collect($events)
                    ->map(fn ($event) => [
                        'property' => $event['key'] . Str::studly($channel) . 'Notifications',
                        'label' => $event['label'],
                        'enabled' => (bool) data_get(
                            $settings,
                            Str::snake($event['key'] . '_' . $channel . '_notifications'),
                        ),
                    ])
                    ->all();
                $groupId = Str::slug($channel . '-' . $group . '-events');
            @endphp
            <div class="min-w-0">
                <x-notification.event-multiselect :settings="$settings" :id="$groupId"
                    :label="$group" :events="$multiselectEvents" />

                @if ($threaded)
                    <div
                        class="mt-3 grid gap-3 border-l border-neutral-200 pl-3 sm:grid-cols-2 dark:border-white/[0.08]">
                        @foreach ($events as $event)
                            @php
                                $threadModel = Str::camel(
                                    Str::studly($channel) . 'Notifications' . Str::studly($event['key']) . 'ThreadId',
                                );
                            @endphp
                            <x-forms.input wire:key="{{ $channel }}-thread-{{ $event['key'] }}"
                                canGate="update" :canResource="$settings" type="password" :id="$threadModel"
                                label="{{ $event['label'] }} thread ID" placeholder="Optional" />
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</x-application.settings-section>
