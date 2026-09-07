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
@endphp

<div class="application-settings-form w-full" wire:poll.5000ms>
    <x-slot:title>
        Deployments | Coolify
    </x-slot>

    <header class="mb-5">
        <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Deployments</h1>
        <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
            Every deployment across the current team, newest first.
        </p>
    </header>

    <div
        class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
        <div
            class="grid grid-cols-2 gap-2 border-b border-neutral-200 p-3 sm:flex sm:items-center dark:border-white/[0.08]">
            @if ($projectOptions)
                <div class="sm:w-44">
                    <x-forms.listbox id="project" live :options="$projectOptions" />
                </div>
            @endif
            @if ($serverOptions)
                <div class="sm:w-44">
                    <x-forms.listbox id="server" live :options="$serverOptions" />
                </div>
            @endif
            @if ($sourceOptions)
                <div class="sm:w-40">
                    <x-forms.listbox id="source" live :options="$sourceOptions" />
                </div>
            @endif
            <div class="sm:w-40">
                <x-forms.listbox id="status" live :options="$statusOptions" />
            </div>
        </div>

        @if ($deployments->isNotEmpty())
            <div class="transition-opacity" wire:loading.class="opacity-50 pointer-events-none"
                wire:target="project,server,source,status,setPage,previousPage,nextPage">
                <div
                    class="dashboard-deployment-table-grid hidden items-center gap-4 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 md:grid dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                    <span>Application</span>
                    <span>Environment</span>
                    <span>Server</span>
                    <span>Status</span>
                    <span>Started</span>
                </div>

                @foreach ($deployments as $deployment)
                    @php
                        [$deploymentStatus, $deploymentStatusType] = $deploymentStatusMeta($deployment->status);
                        $projectName = $deployment->application?->environment?->project?->name;
                        $environmentName = $deployment->application?->environment?->name;
                        $environmentPath = collect([$projectName, $environmentName])->filter()->join(' / ');
                    @endphp

                    <a wire:key="deployment-{{ $deployment->deployment_uuid }}" href="{{ $deployment->deployment_url }}"
                        {{ wireNavigate() }}
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
                        <p class="hidden truncate text-[12px] text-neutral-500 md:block dark:text-fg-dim"
                            title="{{ $deployment->created_at?->toDayDateTimeString() }}">
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

            <x-table-pagination :from="$deployments->firstItem() ?? 0" :to="$deployments->lastItem() ?? 0"
                :total="$deployments->total()" :current-page="$deployments->currentPage()"
                :last-page="$deployments->lastPage()" wire-target="setPage,previousPage,nextPage"
                previous-action="previousPage" next-action="nextPage">
                <x-slot:pageSize>
                    <x-page-size-select model="perPage" livewire storage-key="coolify.page-size.deployments" />
                </x-slot:pageSize>
            </x-table-pagination>
        @else
            <x-empty title="No deployments found"
                description="Deployments of this team's applications will appear here." icon-name="time-back" size="sm" />
        @endif
    </div>
</div>
