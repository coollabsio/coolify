<div class="mt-8" wire:poll.10s>
    <div class="flex items-center gap-2 mb-4">
        <h3>Recent Executions</h3>
        <x-forms.button type="button" wire:click="$refresh" class="!py-1 !px-3 !text-xs">
            Refresh
        </x-forms.button>
    </div>
    @if ($this->recentExecutions->isEmpty())
        <div class="text-sm text-neutral-500">No runner executions yet. When a workflow job matches this server's labels, it will appear here.</div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left border-b border-neutral-700">
                        <th class="pb-2 pr-4">Runner</th>
                        <th class="pb-2 pr-4">Workflow</th>
                        <th class="pb-2 pr-4">Repository</th>
                        <th class="pb-2 pr-4">Status</th>
                        <th class="pb-2 pr-4">Duration</th>
                        <th class="pb-2 pr-4">Started</th>
                        <th class="pb-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->recentExecutions as $execution)
                        <tr class="border-b border-neutral-800">
                            <td class="py-2 pr-4 font-mono text-xs">{{ $execution->runner_name }}</td>
                            <td class="py-2 pr-4">{{ $execution->workflow_name ?? '-' }}</td>
                            <td class="py-2 pr-4">{{ $execution->repository_full_name ?? '-' }}</td>
                            <td class="py-2 pr-4">
                                @switch($execution->status->value)
                                    @case('queued')
                                        <span class="px-2 py-0.5 text-xs rounded-full bg-warning/20 text-warning">Queued</span>
                                        @break
                                    @case('provisioning')
                                        <span class="px-2 py-0.5 text-xs rounded-full bg-blue-500/20 text-blue-400">Provisioning</span>
                                        @break
                                    @case('running')
                                        <span class="px-2 py-0.5 text-xs rounded-full bg-success/20 text-success">Running</span>
                                        @break
                                    @case('completed')
                                        <span class="px-2 py-0.5 text-xs rounded-full bg-neutral-500/20 text-neutral-400">Completed</span>
                                        @break
                                    @case('failed')
                                        <span class="px-2 py-0.5 text-xs rounded-full bg-error/20 text-error">Failed</span>
                                        @break
                                    @case('timed_out')
                                        <span class="px-2 py-0.5 text-xs rounded-full bg-orange-500/20 text-orange-400">Timed Out</span>
                                        @break
                                    @case('cleaning')
                                        <span class="px-2 py-0.5 text-xs rounded-full bg-blue-500/20 text-blue-400">Cleaning</span>
                                        @break
                                @endswitch
                            </td>
                            <td class="py-2 pr-4">{{ $execution->duration() ?? '-' }}</td>
                            <td class="py-2 pr-4">{{ $execution->started_at?->diffForHumans() ?? $execution->created_at->diffForHumans() }}</td>
                            <td class="py-2 whitespace-nowrap">
                                <div class="flex items-center gap-2 flex-nowrap whitespace-nowrap">
                                    @if ($execution->workflowJobUrl())
                                        <a href="{{ $execution->workflowJobUrl() }}" target="_blank" rel="noopener noreferrer"
                                            class="flex hover:no-underline">
                                            <x-forms.button type="button" class="!py-0.5 !px-2 !text-xs">
                                                Open
                                                <x-external-link />
                                            </x-forms.button>
                                        </a>
                                    @endif
                                    @if ($execution->isActive())
                                        <x-forms.button
                                            wire:click="$dispatch('cancel-github-runner-execution', { executionId: {{ $execution->id }} })"
                                            wire:confirm="Cancel this runner? This will kill the process, remove the runner directory, and deregister from GitHub."
                                            canGate="update" :canResource="$server"
                                            class="!py-0.5 !px-2 !text-xs">
                                            Cancel
                                        </x-forms.button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
