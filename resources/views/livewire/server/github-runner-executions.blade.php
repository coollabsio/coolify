<div wire:poll.10s>
    @forelse ($this->executions as $execution)
        <div wire:key="github-runner-execution-{{ $execution->uuid }}"
            class="flex items-center gap-4 border-b border-neutral-200 px-4 py-3 last:border-b-0 dark:border-white/[0.08]">
            <x-status-badge :status="$execution->status->label()" :type="match ($execution->status) {
                \App\Enums\GithubRunnerStatus::Completed => $execution->conclusion === 'success' ? 'success' : 'neutral',
                \App\Enums\GithubRunnerStatus::Failed, \App\Enums\GithubRunnerStatus::TimedOut => 'error',
                \App\Enums\GithubRunnerStatus::Cancelled => 'neutral',
                default => 'warning',
            }" />
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-neutral-950 dark:text-fg">
                    {{ $execution->repository_full_name ?? 'Unknown repository' }}
                    @if ($execution->workflow_name || $execution->job_name)
                        <span class="font-normal text-neutral-500 dark:text-fg-dim">
                            · {{ collect([$execution->workflow_name, $execution->job_name])->filter()->implode(' / ') }}
                        </span>
                    @endif
                </p>
                <p class="mt-0.5 truncate text-xs text-neutral-500 dark:text-fg-dim">
                    {{ $execution->created_at->diffForHumans() }}
                    @if ($execution->duration())
                        · ran {{ $execution->duration() }}
                    @endif
                    @if ($execution->conclusion)
                        · {{ $execution->conclusion }}
                    @endif
                    @if ($execution->error_message)
                        · {{ $execution->error_message }}
                    @endif
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                @if ($execution->workflowJobUrl())
                    <a href="{{ $execution->workflowJobUrl() }}" target="_blank" rel="noopener noreferrer"
                        class="button">
                        Open
                        <x-external-link />
                    </a>
                @endif
                @if ($execution->isActive())
                    @can('update', $server)
                        <x-modal-confirmation title="Cancel this runner?" buttonTitle="Cancel"
                            submitAction="cancel({{ $execution->id }})" :actions="[
                                'The runner will be stopped and removed.',
                                'A running workflow job fails.',
                            ]"
                            :confirmWithText="false" :confirmWithPassword="false"
                            step2ButtonText="Cancel Runner" isErrorButton />
                    @endcan
                @endif
            </div>
        </div>
    @empty
        <x-empty size="sm" title="No runners yet"
            description="Workflow jobs that ask for this server's labels appear here." icon-name="servers" />
    @endforelse
</div>
