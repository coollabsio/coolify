<div x-data="{ search: '' }">
    <x-forms.input placeholder="Search resources..." x-model="search" id="null" />
    @if ($groupedBackups->count() > 0)
        <div class="overflow-x-auto pt-4">
            <div class="inline-block min-w-full">
                <div class="overflow-hidden">
                    <table class="min-w-full">
                        <thead>
                            <tr>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Database</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Frequency</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Status</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">S3 Storage</th>
                            </tr>
                        </thead>
                        <tbody>
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
                                    <tr class="dark:hover:bg-coolgray-300 hover:bg-neutral-100" x-show="search === '' || '{{ strtolower(addslashes($databaseName)) }}'.includes(search.toLowerCase()) || '{{ strtolower(addslashes($backup->frequency)) }}'.includes(search.toLowerCase())">
                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                            @if ($resourceLink)
                                                <a class="hover:underline" {{ wireNavigate() }} href="{{ $resourceLink }}">{{ $databaseName }} <x-internal-link /></a>
                                            @else
                                                {{ $databaseName }}
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                            @php
                                                $backupLink = null;
                                                if ($backupParams) {
                                                    $backupLink = route('project.database.backup.execution', array_merge($backupParams, [
                                                        'backup_uuid' => $backup->uuid,
                                                    ]));
                                                }
                                            @endphp
                                            @if ($backupLink)
                                                <a class="hover:underline" {{ wireNavigate() }} href="{{ $backupLink }}">{{ $backup->frequency }} <x-internal-link /></a>
                                            @else
                                                {{ $backup->frequency }}
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-sm font-medium whitespace-nowrap">
                                            @if ($backup->enabled)
                                                <span class="text-green-500">Enabled</span>
                                            @else
                                                <span class="text-yellow-500">Disabled</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                                            <div class="flex items-center gap-2">
                                                <select wire:model="selectedStorages.{{ $backup->id }}" class="w-full input">
                                                    @foreach ($allStorages as $s3)
                                                        <option value="{{ $s3->id }}" @disabled(!$s3->is_usable)>{{ $s3->name }}@if (!$s3->is_usable) (unusable)@endif</option>
                                                    @endforeach
                                                </select>
                                                <x-forms.button wire:click="moveBackup({{ $backup->id }})">Save</x-forms.button>
                                                <x-forms.button isError wire:click="disableS3({{ $backup->id }})" wire:confirm="Are you sure you want to disable S3 for this backup schedule?">Disable S3</x-forms.button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @else
        <div class="pt-4">No backup schedules are using this storage.</div>
    @endif
</div>
