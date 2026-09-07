@php
    $deploymentStatusMeta = function (string $status): array {
        $label = match ($status) {
            'finished' => 'Success',
            'in_progress' => 'In progress',
            'cancelled-by-user' => 'Cancelled',
            'queued' => 'Queued',
            'failed' => 'Failed',
            default => str($status)->headline()->toString(),
        };

        $type = match ($status) {
            'finished' => 'success',
            'in_progress', 'queued' => 'warning',
            'failed' => 'error',
            default => 'neutral',
        };

        return [$label, $type];
    };

    $hasActiveDeployments = $activeDeployments->isNotEmpty();
    $hasRecentDeployments = $recentDeployments->isNotEmpty();
    $hasAnyDeployments = $hasActiveDeployments || $hasRecentDeployments;
@endphp

<div wire:poll.3000ms="refreshDeployments" @class(['mb-0! min-w-0' => $hasAnyDeployments])>
    @if ($hasAnyDeployments)
        <section class="mb-0! min-w-0">
            <div class="mb-3">
                <div>
                    <h2 class="text-[14px]! leading-5! font-semibold! text-black dark:text-fg">
                        Deployments
                    </h2>
                    <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                        Active and recent deployment activity
                    </p>
                </div>
            </div>

            <div class="flex min-w-0 flex-col gap-4">
                @if ($hasActiveDeployments)
                    <div
                        class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
                        <div class="border-b border-neutral-200 px-4 py-2.5 dark:border-white/[0.08]">
                            <h3 class="text-[12px]! leading-4! font-semibold! text-black dark:text-fg">
                                Active
                            </h3>
                            <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                                Running or queued right now
                            </p>
                        </div>

                        <div
                            class="dashboard-deployment-table-grid hidden items-center gap-4 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 md:grid dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                            <span>Application</span>
                            <span>Environment</span>
                            <span>Server</span>
                            <span>Status</span>
                            <span>Started</span>
                        </div>

                        @foreach ($activeDeployments as $deployment)
                            @php
                                [$deploymentStatus, $deploymentStatusType] = $deploymentStatusMeta($deployment->status);
                                $projectName = $deployment->application?->environment?->project?->name;
                                $environmentName = $deployment->application?->environment?->name;
                                $environmentPath = collect([$projectName, $environmentName])->filter()->join(' / ');
                            @endphp

                            <a wire:key="dashboard-active-deployment-{{ $deployment->deployment_uuid }}"
                                href="{{ $deployment->deployment_url }}" {{ wireNavigate() }}
                                class="dashboard-deployment-table-grid group block border-b border-neutral-200 px-4 py-3 transition-colors last:border-b-0 hover:bg-neutral-50 focus-visible:z-10 focus-visible:bg-warning/10 focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-warning focus-visible:ring-offset-0 md:grid md:items-center md:gap-4 dark:border-white/[0.08] dark:hover:bg-white/[0.025] dark:focus-visible:bg-warning/10">
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
                                    {{ $environmentPath ?: '-' }}
                                </p>
                                <p class="hidden truncate text-[12px] text-neutral-500 md:block dark:text-fg-dim">
                                    {{ $deployment->server_name ?: '-' }}
                                </p>
                                <span class="hidden md:block">
                                    <x-status-badge :status="$deploymentStatus" :type="$deploymentStatusType" />
                                </span>
                                <p class="hidden truncate text-[12px] text-neutral-500 md:block dark:text-fg-dim">
                                    {{ $deployment->created_at?->diffForHumans() ?? '-' }}
                                </p>

                                <div class="mt-3 flex min-w-0 items-center gap-3 md:hidden">
                                    <x-status-badge :status="$deploymentStatus" :type="$deploymentStatusType" />
                                    <p class="min-w-0 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                        {{ $environmentPath ?: '-' }}
                                        <span class="px-1 text-neutral-300 dark:text-white/15">·</span>
                                        {{ $deployment->server_name ?: '-' }}
                                    </p>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif

                @if ($hasRecentDeployments)
                    <div
                        class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
                        <div class="border-b border-neutral-200 px-4 py-2.5 dark:border-white/[0.08]">
                            <h3 class="text-[12px]! leading-4! font-semibold! text-black dark:text-fg">
                                Recent
                            </h3>
                            <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                                Latest completed deployments
                            </p>
                        </div>

                        <div
                            class="dashboard-deployment-table-grid hidden items-center gap-4 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 md:grid dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                            <span>Application</span>
                            <span>Environment</span>
                            <span>Server</span>
                            <span>Status</span>
                            <span>Started</span>
                        </div>

                        @foreach ($recentDeployments as $deployment)
                            @php
                                [$deploymentStatus, $deploymentStatusType] = $deploymentStatusMeta($deployment->status);
                                $projectName = $deployment->application?->environment?->project?->name;
                                $environmentName = $deployment->application?->environment?->name;
                                $environmentPath = collect([$projectName, $environmentName])->filter()->join(' / ');
                            @endphp

                            <a wire:key="dashboard-recent-deployment-{{ $deployment->deployment_uuid }}"
                                href="{{ $deployment->deployment_url }}" {{ wireNavigate() }}
                                class="dashboard-deployment-table-grid group block border-b border-neutral-200 px-4 py-3 transition-colors last:border-b-0 hover:bg-neutral-50 focus-visible:z-10 focus-visible:bg-warning/10 focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-warning focus-visible:ring-offset-0 md:grid md:items-center md:gap-4 dark:border-white/[0.08] dark:hover:bg-white/[0.025] dark:focus-visible:bg-warning/10">
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
                                    {{ $environmentPath ?: '-' }}
                                </p>
                                <p class="hidden truncate text-[12px] text-neutral-500 md:block dark:text-fg-dim">
                                    {{ $deployment->server_name ?: '-' }}
                                </p>
                                <span class="hidden md:block">
                                    <x-status-badge :status="$deploymentStatus" :type="$deploymentStatusType" />
                                </span>
                                <p class="hidden truncate text-[12px] text-neutral-500 md:block dark:text-fg-dim">
                                    {{ $deployment->created_at?->diffForHumans() ?? '-' }}
                                </p>

                                <div class="mt-3 flex min-w-0 items-center gap-3 md:hidden">
                                    <x-status-badge :status="$deploymentStatus" :type="$deploymentStatusType" />
                                    <p class="min-w-0 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                        {{ $environmentPath ?: '-' }}
                                        <span class="px-1 text-neutral-300 dark:text-white/15">·</span>
                                        {{ $deployment->server_name ?: '-' }}
                                    </p>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    @endif
</div>
