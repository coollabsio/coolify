<div>
    <div class="flex flex-col gap-2">
        @if ($database->is_migrated && blank($database->custom_type))
            <div>
                <div>Select the type of
                    database to enable automated backups.</div>
                <div class="pb-4"> If your database is not listed, automated backups are not supported.</div>
                <form wire:submit="setCustomType" class="flex gap-2 items-end">
                    <div class="w-96">
                        <x-forms.select label="Type" id="custom_type">
                            <option selected value="mysql">MySQL</option>
                            <option value="mariadb">MariaDB</option>
                            <option value="postgresql">PostgreSQL</option>
                            <option value="mongodb">MongoDB</option>
                        </x-forms.select>
                    </div>
                    <x-forms.button type="submit">Set</x-forms.button>
                </form>
            </div>
        @else
            @forelse($database->scheduledBackups as $backup)
                @if ($type == 'database')
                    <a @class([
                        'flex flex-col border-l-2 transition-colors p-4 cursor-pointer bg-white hover:bg-gray-100 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 text-black dark:text-white',
                        'border-blue-500/50 border-dashed' =>
                            $backup->latest_log &&
                            data_get($backup->latest_log, 'status') === 'running',
                        'border-error' =>
                            $backup->latest_log &&
                            data_get($backup->latest_log, 'status') === 'failed',
                        'border-success' =>
                            $backup->latest_log &&
                            data_get($backup->latest_log, 'status') === 'success',
                        'border-gray-200 dark:border-coolgray-300' => !$backup->latest_log,
                    ])
                        href="{{ route('project.database.backup.execution', [...$parameters, 'backup_uuid' => $backup->uuid]) }}">
                        @if ($backup->latest_log && data_get($backup->latest_log, 'status') === 'running')
                            <div class="absolute top-2 right-2">
                                <x-loading />
                            </div>
                        @endif
                        <div class="flex items-center gap-2 mb-2">
                            @if ($backup->latest_log)
                                <span @class([
                                    'px-3 py-1 rounded-md text-xs font-medium tracking-wide shadow-xs',
                                    'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300 dark:shadow-blue-900/5' =>
                                        data_get($backup->latest_log, 'status') === 'running',
                                    'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200 dark:shadow-red-900/5' =>
                                        data_get($backup->latest_log, 'status') === 'failed',
                                    'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200 dark:shadow-green-900/5' =>
                                        data_get($backup->latest_log, 'status') === 'success',
                                ])>
                                    @php
                                        $statusText = match (data_get($backup->latest_log, 'status')) {
                                            'success' => 'Success',
                                            'running' => 'In Progress',
                                            'failed' => 'Failed',
                                            default => ucfirst(data_get($backup->latest_log, 'status')),
                                        };
                                    @endphp
                                    {{ $statusText }}
                                </span>
                            @else
                                <span
                                    class="px-3 py-1 rounded-md text-xs font-medium tracking-wide shadow-xs bg-gray-100 text-gray-800 dark:bg-neutral-800 dark:text-gray-200">
                                    No executions yet
                                </span>
                            @endif
                            <h3 class="font-semibold">{{ $backup->frequency }}</h3>
                        </div>
                        <div class="text-gray-600 dark:text-gray-400 text-sm">
                            @if ($backup->latest_log)
                                @if (data_get($backup->latest_log, 'status') === 'running')
                                    <span title="Started: {{ formatDateInServerTimezone(data_get($backup->latest_log, 'created_at'), $backup->server()) }}">
                                        Running for {{ calculateDuration(data_get($backup->latest_log, 'created_at'), now()) }}
                                    </span>
                                @else
                                    <span title="Started: {{ formatDateInServerTimezone(data_get($backup->latest_log, 'created_at'), $backup->server()) }}&#10;Ended: {{ formatDateInServerTimezone(data_get($backup->latest_log, 'finished_at'), $backup->server()) }}">
                                        {{ \Carbon\Carbon::parse(data_get($backup->latest_log, 'finished_at'))->diffForHumans() }}
                                        ({{ calculateDuration(data_get($backup->latest_log, 'created_at'), data_get($backup->latest_log, 'finished_at')) }})
                                        • {{ \Carbon\Carbon::parse(data_get($backup->latest_log, 'finished_at'))->format('M j, H:i') }}
                                    </span>
                                @endif
                                @if (data_get($backup->latest_log, 'status') === 'success')
                                    @php
                                        $size = data_get($backup->latest_log, 'size', 0);
                                    @endphp
                                    @if ($size > 0)
                                        • Size: {{ formatBytes($size) }}
                                    @endif
                                @endif
                                @if ($backup->save_s3)
                                    • S3: Enabled
                                @endif
                            @else
                                Last Run: Never • Total Executions: 0
                                @if ($backup->save_s3)
                                    • S3: Enabled
                                @endif
                            @endif
                        </div>
                    </a>
                @else
                    <div @class([
                        'flex flex-col border-l-2 transition-colors p-4 cursor-pointer bg-white hover:bg-gray-100 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 text-black dark:text-white',
                        'bg-gray-200 dark:bg-coolgray-200' =>
                            data_get($backup, 'id') === data_get($selectedBackup, 'id'),
                        'border-blue-500/50 border-dashed' =>
                            $backup->latest_log &&
                            data_get($backup->latest_log, 'status') === 'running',
                        'border-error' =>
                            $backup->latest_log &&
                            data_get($backup->latest_log, 'status') === 'failed',
                        'border-success' =>
                            $backup->latest_log &&
                            data_get($backup->latest_log, 'status') === 'success',
                        'border-gray-200 dark:border-coolgray-300' => !$backup->latest_log,
                        'border-coollabs' =>
                            data_get($backup, 'id') === data_get($selectedBackup, 'id'),
                    ]) wire:click="setSelectedBackup('{{ data_get($backup, 'id') }}')">
                        @if ($backup->latest_log && data_get($backup->latest_log, 'status') === 'running')
                            <div class="absolute top-2 right-2">
                                <x-loading />
                            </div>
                        @endif
                        <div class="flex items-center gap-2 mb-2">
                            @if ($backup->latest_log)
                                <span @class([
                                    'px-3 py-1 rounded-md text-xs font-medium tracking-wide shadow-xs',
                                    'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300 dark:shadow-blue-900/5' =>
                                        data_get($backup->latest_log, 'status') === 'running',
                                    'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200 dark:shadow-red-900/5' =>
                                        data_get($backup->latest_log, 'status') === 'failed',
                                    'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200 dark:shadow-green-900/5' =>
                                        data_get($backup->latest_log, 'status') === 'success',
                                ])>
                                    @php
                                        $statusText = match (data_get($backup->latest_log, 'status')) {
                                            'success' => 'Success',
                                            'running' => 'In Progress',
                                            'failed' => 'Failed',
                                            default => ucfirst(data_get($backup->latest_log, 'status')),
                                        };
                                    @endphp
                                    {{ $statusText }}
                                </span>
                            @else
                                <span
                                    class="px-3 py-1 rounded-md text-xs font-medium tracking-wide shadow-xs bg-gray-100 text-gray-800 dark:bg-neutral-800 dark:text-gray-200">
                                    No executions yet
                                </span>
                            @endif
                            <h3 class="font-semibold">{{ $backup->frequency }} Backup</h3>
                        </div>
                        <div class="text-gray-600 dark:text-gray-400 text-sm">
                            @if ($backup->latest_log)
                                @if (data_get($backup->latest_log, 'status') === 'running')
                                    <span title="Started: {{ formatDateInServerTimezone(data_get($backup->latest_log, 'created_at'), $backup->server()) }}">
                                        Running for {{ calculateDuration(data_get($backup->latest_log, 'created_at'), now()) }}
                                    </span>
                                @else
                                    <span title="Started: {{ formatDateInServerTimezone(data_get($backup->latest_log, 'created_at'), $backup->server()) }}&#10;Ended: {{ formatDateInServerTimezone(data_get($backup->latest_log, 'finished_at'), $backup->server()) }}">
                                        {{ \Carbon\Carbon::parse(data_get($backup->latest_log, 'finished_at'))->diffForHumans() }}
                                        ({{ calculateDuration(data_get($backup->latest_log, 'created_at'), data_get($backup->latest_log, 'finished_at')) }})
                                        • {{ \Carbon\Carbon::parse(data_get($backup->latest_log, 'finished_at'))->format('M j, H:i') }}
                                    </span>
                                @endif
                                @if (data_get($backup->latest_log, 'status') === 'success')
                                    @php
                                        $size = data_get($backup->latest_log, 'size', 0);
                                    @endphp
                                    @if ($size > 0)
                                        • Size: {{ formatBytes($size) }}
                                    @endif
                                @endif
                                @if ($backup->save_s3)
                                    • S3: Enabled
                                @endif
                                <br>Total Executions: {{ $backup->executions()->count() }}
                                @php
                                    $successCount = $backup->executions()->where('status', 'success')->count();
                                    $totalCount = $backup->executions()->count();
                                    $successRate = $totalCount > 0 ? round(($successCount / $totalCount) * 100) : 0;
                                @endphp
                                @if ($totalCount > 0)
                                    • Success Rate: <span @class([
                                        'font-medium',
                                        'text-green-600' => $successRate >= 80,
                                        'text-yellow-600' => $successRate >= 50 && $successRate < 80,
                                        'text-red-600' => $successRate < 50,
                                    ])>{{ $successRate }}%</span>
                                    ({{ $successCount }}/{{ $totalCount }})
                                @endif
                            @else
                                Last Run: Never • Total Executions: 0
                                @if ($backup->save_s3)
                                    • S3: Enabled
                                @endif
                            @endif
                        </div>
                    </div>
                @endif
            @empty
                <div>No scheduled backups configured.</div>
            @endforelse
        @endif
    </div>
    @if ($type === 'service-database' && $selectedBackup)
        <div class="pt-10">
            <livewire:project.database.backup-edit wire:key="{{ $selectedBackup->id }}" :backup="$selectedBackup"
                :s3s="$s3s" :status="data_get($database, 'status')" />
            <livewire:project.database.backup-executions wire:key="{{ $selectedBackup->uuid }}" :backup="$selectedBackup"
                :database="$database" />
        </div>
    @endif
</div>
