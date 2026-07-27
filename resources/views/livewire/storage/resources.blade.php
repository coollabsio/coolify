<div x-data="{ search: '' }" class="application-settings-form">
    <x-application.settings-section title="Backup schedules"
        description="Schedules currently writing backup data to this storage." flush>
        @if ($groupedBackups->count() === 0)
            <x-empty title="No backup schedules use this storage"
                description="Select this storage from a database or volume backup schedule to see it here."
                size="sm">
                <x-slot:icon>
                    <x-reicon name="storages" class="size-6" />
                </x-slot:icon>
            </x-empty>
        @else
            <div class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                <div class="relative w-full max-w-sm">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input x-model.debounce.150ms="search" type="search" placeholder="Search backup schedules"
                        class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-3! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-neutral-300! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                </div>
            </div>

            <div class="overflow-x-auto">
                <div
                    class="grid min-w-[780px] grid-cols-[minmax(12rem,1fr)_9rem_7rem_minmax(15rem,1.2fr)] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                    <div>Database</div>
                    <div>Frequency</div>
                    <div>Status</div>
                    <div>Storage</div>
                </div>
                @foreach ($groupedBackups as $backups)
                    @php
                        $firstBackup = $backups->first();
                        $database = $firstBackup->database;
                        $databaseName = $database?->name ?? 'Deleted database';
                        $resourceLink = null;
                        $backupParams = null;
                        if ($database && $database instanceof \App\Models\ServiceDatabase) {
                            $service = $database->service;
                            if ($service) {
                                $environment = $service->environment;
                                $project = $environment?->project;
                                if ($project && $environment) {
                                    $resourceLink = route('project.service.configuration', [
                                        'project_uuid' => $project->uuid,
                                        'environment_uuid' => $environment->uuid,
                                        'service_uuid' => $service->uuid,
                                    ]);
                                }
                            }
                        } elseif ($database) {
                            $environment = $database->environment;
                            $project = $environment?->project;
                            if ($project && $environment) {
                                $resourceLink = route('project.database.backup.index', [
                                    'project_uuid' => $project->uuid,
                                    'environment_uuid' => $environment->uuid,
                                    'database_uuid' => $database->uuid,
                                ]);
                                $backupParams = [
                                    'project_uuid' => $project->uuid,
                                    'environment_uuid' => $environment->uuid,
                                    'database_uuid' => $database->uuid,
                                ];
                            }
                        }
                    @endphp
                    @foreach ($backups as $backup)
                        @php
                            $backupLink = $backupParams
                                ? route('project.database.backup.execution', [
                                    ...$backupParams,
                                    'backup_uuid' => $backup->uuid,
                                ])
                                : null;
                            $storageOptions = $allStorages->map(fn ($s3) => [
                                'value' => $s3->id,
                                'label' => $s3->name.($s3->is_usable ? '' : ' (unusable)'),
                                'disabled' => ! $s3->is_usable,
                            ])->values()->all();
                        @endphp
                        <div
                            class="grid min-h-14 min-w-[780px] grid-cols-[minmax(12rem,1fr)_9rem_7rem_minmax(15rem,1.2fr)] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] last:border-b-0 dark:border-white/[0.07]"
                            x-show="search === '' || '{{ strtolower(addslashes($databaseName)) }}'.includes(search.toLowerCase()) || '{{ strtolower(addslashes($backup->frequency)) }}'.includes(search.toLowerCase())">
                            <div class="min-w-0">
                                @if ($resourceLink)
                                    <a class="truncate font-medium text-black hover:underline dark:text-fg"
                                        {{ wireNavigate() }} href="{{ $resourceLink }}">{{ $databaseName }}</a>
                                @else
                                    <span class="truncate font-medium text-black dark:text-fg">{{ $databaseName }}</span>
                                @endif
                            </div>
                            <div>
                                @if ($backupLink)
                                    <a class="text-neutral-500 hover:underline dark:text-fg-dim" {{ wireNavigate() }}
                                        href="{{ $backupLink }}">{{ $backup->frequency }}</a>
                                @else
                                    <span class="text-neutral-500 dark:text-fg-dim">{{ $backup->frequency }}</span>
                                @endif
                            </div>
                            <x-status-badge :status="$backup->enabled ? 'Enabled' : 'Disabled'"
                                :type="$backup->enabled ? 'success' : 'warning'" />
                            <div class="flex items-end gap-2">
                                <x-forms.listbox id="selectedStorages.{{ $backup->id }}" :options="$storageOptions" />
                                <button type="button" class="button shrink-0"
                                    wire:click="moveBackup({{ $backup->id }})">Move</button>
                                <button type="button" class="button shrink-0 text-error"
                                    wire:click="disableS3({{ $backup->id }})"
                                    wire:confirm="Are you sure you want to disable S3 for this backup schedule?">
                                    Disable
                                </button>
                            </div>
                        </div>
                    @endforeach
                @endforeach
            </div>
        @endif
    </x-application.settings-section>
</div>
