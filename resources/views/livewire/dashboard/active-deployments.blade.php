<section class="mb-0! min-w-0" wire:poll.3000ms="refreshDeployments">
    <div class="mb-3">
        <div>
            <h2 class="text-[14px]! leading-5! font-semibold! text-black dark:text-fg">
                Active deployments
            </h2>
            <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                Applications currently running or waiting
            </p>
        </div>
    </div>

    <div
        class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
        @if ($deployments->isEmpty())
            <div class="flex min-h-20 items-center gap-3 px-4 py-4">
                <div
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-400 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-faint">
                    <x-reicon name="check-circle" class="size-4" />
                </div>
                <div class="min-w-0">
                    <h3 class="text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                        No active deployments
                    </h3>
                    <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                        Running and queued deployments will appear here.
                    </p>
                </div>
            </div>
        @else
            <div
                class="dashboard-deployment-table-grid hidden items-center gap-4 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 md:grid dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                <span>Application</span>
                <span>Environment</span>
                <span>Server</span>
                <span>Status</span>
                <span>Started</span>
                <span></span>
            </div>

            @foreach ($deployments as $deployment)
                @php
                    $deploymentStatus = $deployment->status === 'in_progress' ? 'In progress' : 'Queued';
                    $deploymentStatusType = $deployment->status === 'in_progress' ? 'warning' : 'neutral';
                    $projectName = $deployment->application?->environment?->project?->name;
                    $environmentName = $deployment->application?->environment?->name;
                    $environmentPath = collect([$projectName, $environmentName])->filter()->join(' / ');
                @endphp

                <a wire:key="dashboard-deployment-{{ $deployment->deployment_uuid }}"
                    href="{{ $deployment->deployment_url }}" {{ wireNavigate() }}
                    class="dashboard-deployment-table-grid group block border-b border-neutral-200 px-4 py-3 transition-colors last:border-b-0 hover:bg-neutral-50 md:grid md:items-center md:gap-4 dark:border-white/[0.08] dark:hover:bg-white/[0.025]">
                    <div class="min-w-0">
                        <p class="truncate text-[13px] font-semibold text-black dark:text-fg">
                            {{ $deployment->application_name }}
                        </p>
                        @if ($deployment->pull_request_id)
                            <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                                Pull request #{{ $deployment->pull_request_id }}
                            </p>
                        @endif
                    </div>

                    <p class="hidden truncate text-[12px] text-neutral-500 md:block dark:text-fg-dim">
                        {{ $environmentPath ?: '—' }}
                    </p>
                    <p class="hidden truncate text-[12px] text-neutral-500 md:block dark:text-fg-dim">
                        {{ $deployment->server_name ?: '—' }}
                    </p>
                    <span class="hidden md:block">
                        <x-status-badge :status="$deploymentStatus" :type="$deploymentStatusType" />
                    </span>
                    <p class="hidden truncate text-[12px] text-neutral-500 md:block dark:text-fg-dim">
                        {{ $deployment->created_at?->diffForHumans() ?? '—' }}
                    </p>
                    <x-reicon name="arrow-right"
                        class="hidden size-3 text-neutral-400 transition-colors group-hover:text-black md:block dark:text-fg-faint dark:group-hover:text-fg" />

                    <div class="mt-3 flex min-w-0 items-center justify-between gap-3 md:hidden">
                        <x-status-badge :status="$deploymentStatus" :type="$deploymentStatusType" />
                        <p class="min-w-0 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                            {{ $environmentPath ?: '—' }}
                            <span class="px-1 text-neutral-300 dark:text-white/15">·</span>
                            {{ $deployment->server_name ?: '—' }}
                        </p>
                        <x-reicon name="arrow-right" class="size-3 shrink-0 text-neutral-400 dark:text-fg-faint" />
                    </div>
                </a>
            @endforeach
        @endif
    </div>
</section>
