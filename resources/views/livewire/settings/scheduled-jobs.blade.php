<div>
    <x-slot:title>
        Scheduled Job Issues | Coolify
    </x-slot>
    <x-settings.navbar />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'executions' }"
        class="flex flex-col gap-8">
        <div>
            <div class="flex items-center gap-2">
                <h2>Scheduled Job Issues</h2>
                <x-forms.button wire:click="refresh">Refresh</x-forms.button>
            </div>
            <div class="pb-4">Shows failed executions, skipped jobs, and scheduler health.</div>
        </div>

        {{-- Tab Buttons --}}
        <div class="flex flex-row gap-4">
            <div @class([
                    'box-without-bg cursor-pointer dark:bg-coolgray-100 dark:text-white w-full text-center items-center justify-center',
                ])
                :class="activeTab === 'executions' && 'dark:bg-coollabs bg-coollabs text-white'"
                @click="activeTab = 'executions'; window.location.hash = 'executions'">
                Failures ({{ $executions->count() }})
            </div>
            <div @class([
                    'box-without-bg cursor-pointer dark:bg-coolgray-100 dark:text-white w-full text-center items-center justify-center',
                ])
                :class="activeTab === 'scheduler-runs' && 'dark:bg-coollabs bg-coollabs text-white'"
                @click="activeTab = 'scheduler-runs'; window.location.hash = 'scheduler-runs'">
                Scheduler Runs ({{ $managerRuns->count() }})
            </div>
            <div @class([
                    'box-without-bg cursor-pointer dark:bg-coolgray-100 dark:text-white w-full text-center items-center justify-center',
                ])
                :class="activeTab === 'skipped-jobs' && 'dark:bg-coollabs bg-coollabs text-white'"
                @click="activeTab = 'skipped-jobs'; window.location.hash = 'skipped-jobs'">
                Skipped Jobs ({{ $skipLogs->count() }})
            </div>
        </div>

        {{-- Executions Tab --}}
        <div x-show="activeTab === 'executions'" x-cloak>
            {{-- Filters --}}
            <div class="flex gap-4 flex-wrap mb-4">
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium">Type</label>
                    <select wire:model.live="filterType"
                        class="w-40 border bg-white dark:bg-coolgray-100 border-gray-300 dark:border-coolgray-400 rounded-md text-sm">
                        <option value="all">All Types</option>
                        <option value="backup">Backups</option>
                        <option value="task">Tasks</option>
                        <option value="cleanup">Docker Cleanup</option>
                    </select>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium">Time Range</label>
                    <select wire:model.live="filterDate"
                        class="w-40 border bg-white dark:bg-coolgray-100 border-gray-300 dark:border-coolgray-400 rounded-md text-sm">
                        <option value="last_24h">Last 24 Hours</option>
                        <option value="last_7d">Last 7 Days</option>
                        <option value="last_30d">Last 30 Days</option>
                        <option value="all">All Time</option>
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs uppercase bg-gray-50 dark:bg-coolgray-200">
                        <tr>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Resource</th>
                            <th class="px-4 py-3">Server</th>
                            <th class="px-4 py-3">Started</th>
                            <th class="px-4 py-3">Duration</th>
                            <th class="px-4 py-3">Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($executions as $execution)
                            <tr wire:key="exec-{{ $execution['type'] }}-{{ $execution['id'] }}"
                                class="border-b border-gray-200 dark:border-coolgray-400 hover:bg-gray-50 dark:hover:bg-coolgray-200">
                                <td class="px-4 py-3">
                                    @php
                                        $typeLabel = match($execution['type']) {
                                            'backup' => 'Backup',
                                            'task' => 'Task',
                                            'cleanup' => 'Cleanup',
                                            default => ucfirst($execution['type']),
                                        };
                                        $typeBg = match($execution['type']) {
                                            'backup' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
                                            'task' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
                                            'cleanup' => 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
                                            default => 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-300',
                                        };
                                    @endphp
                                    <span class="px-2 py-1 rounded-md text-xs font-medium {{ $typeBg }}">
                                        {{ $typeLabel }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    {{ $execution['resource_name'] }}
                                    @if($execution['resource_type'])
                                        <span class="text-xs text-gray-500">({{ $execution['resource_type'] }})</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $execution['server_name'] }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    {{ $execution['created_at']->diffForHumans() }}
                                    <span class="block text-xs text-gray-500">{{ $execution['created_at']->format('M d H:i') }}</span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    @if($execution['finished_at'] && $execution['created_at'])
                                        {{ \Carbon\Carbon::parse($execution['created_at'])->diffInSeconds(\Carbon\Carbon::parse($execution['finished_at'])) }}s
                                    @elseif($execution['status'] === 'running')
                                        <x-loading class="w-4 h-4" />
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-4 py-3 max-w-xs truncate" title="{{ $execution['message'] }}">
                                    {{ \Illuminate\Support\Str::limit($execution['message'], 80) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                    No failures found for the selected filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Scheduler Runs Tab --}}
        <div x-show="activeTab === 'scheduler-runs'" x-cloak>
            <div class="pb-4 text-sm text-gray-500">Shows when the ScheduledJobManager executed. Gaps indicate lock conflicts or missed runs.</div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs uppercase bg-gray-50 dark:bg-coolgray-200">
                        <tr>
                            <th class="px-4 py-3">Time</th>
                            <th class="px-4 py-3">Event</th>
                            <th class="px-4 py-3">Duration</th>
                            <th class="px-4 py-3">Dispatched</th>
                            <th class="px-4 py-3">Skipped</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($managerRuns as $run)
                            <tr wire:key="run-{{ $loop->index }}"
                                class="border-b border-gray-200 dark:border-coolgray-400">
                                <td class="px-4 py-2 whitespace-nowrap text-xs">{{ $run['timestamp'] }}</td>
                                <td class="px-4 py-2">{{ $run['message'] }}</td>
                                <td class="px-4 py-2">
                                    @if($run['duration_ms'] !== null)
                                        {{ $run['duration_ms'] }}ms
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-4 py-2">{{ $run['dispatched'] ?? '-' }}</td>
                                <td class="px-4 py-2">
                                    @if(($run['skipped'] ?? 0) > 0)
                                        <span class="text-warning">{{ $run['skipped'] }}</span>
                                    @else
                                        {{ $run['skipped'] ?? '-' }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-4 text-center text-gray-500">
                                    No scheduler run logs found. Logs appear after the ScheduledJobManager runs.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Skipped Jobs Tab --}}
        <div x-show="activeTab === 'skipped-jobs'" x-cloak>
            <div class="pb-4 text-sm text-gray-500">Jobs that were not dispatched because conditions were not met.</div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs uppercase bg-gray-50 dark:bg-coolgray-200">
                        <tr>
                            <th class="px-4 py-3">Time</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Reason</th>
                            <th class="px-4 py-3">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($skipLogs as $skip)
                            <tr wire:key="skip-{{ $loop->index }}"
                                class="border-b border-gray-200 dark:border-coolgray-400">
                                <td class="px-4 py-2 whitespace-nowrap text-xs">{{ $skip['timestamp'] }}</td>
                                <td class="px-4 py-2">
                                    @php
                                        $skipTypeBg = match($skip['type']) {
                                            'backup' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
                                            'task' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
                                            'docker_cleanup' => 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
                                            default => 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-300',
                                        };
                                    @endphp
                                    <span class="px-2 py-1 rounded-md text-xs font-medium {{ $skipTypeBg }}">
                                        {{ ucfirst(str_replace('_', ' ', $skip['type'])) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2">
                                    @php
                                        $reasonLabel = match($skip['reason']) {
                                            'server_not_functional' => 'Server not functional',
                                            'subscription_unpaid' => 'Subscription unpaid',
                                            'database_deleted' => 'Database deleted',
                                            'server_deleted' => 'Server deleted',
                                            'resource_deleted' => 'Resource deleted',
                                            'application_not_running' => 'Application not running',
                                            'service_not_running' => 'Service not running',
                                            default => ucfirst(str_replace('_', ' ', $skip['reason'])),
                                        };
                                        $reasonBg = match($skip['reason']) {
                                            'server_not_functional', 'database_deleted', 'server_deleted', 'resource_deleted' => 'text-red-600 dark:text-red-400',
                                            'subscription_unpaid' => 'text-warning',
                                            'application_not_running', 'service_not_running' => 'text-orange-600 dark:text-orange-400',
                                            default => '',
                                        };
                                    @endphp
                                    <span class="{{ $reasonBg }}">{{ $reasonLabel }}</span>
                                </td>
                                <td class="px-4 py-2 text-xs text-gray-500">
                                    @php
                                        $details = collect($skip['context'])
                                            ->except(['type', 'skip_reason', 'execution_time'])
                                            ->map(fn($v, $k) => str_replace('_', ' ', $k) . ': ' . $v)
                                            ->implode(', ');
                                    @endphp
                                    {{ $details }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-4 text-center text-gray-500">
                                    No skipped jobs found. This means all scheduled jobs passed their conditions.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
