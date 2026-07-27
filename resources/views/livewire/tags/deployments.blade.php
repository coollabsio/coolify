<div wire:poll.2000ms="getDeployments" wire:init="getDeployments">
    <x-application.settings-section title="Active deployments"
        description="Deployments currently running for resources with this tag." flush>
        @if (collect($deploymentsPerTagPerServer)->flatten(1)->isEmpty())
            <x-empty title="No active deployments"
                description="Queued and running deployments will appear here automatically." size="sm">
                <x-slot:icon>
                    <x-reicon name="refresh" class="size-6" />
                </x-slot:icon>
            </x-empty>
        @else
            <div class="data-table w-full">
                <div
                    class="grid grid-cols-[minmax(0,1fr)_minmax(8rem,.55fr)_8rem_2rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                    <span>Resource</span>
                    <span>Server</span>
                    <span>Status</span>
                    <span></span>
                </div>

                @foreach ($deploymentsPerTagPerServer as $serverName => $deployments)
                    @foreach ($deployments as $deployment)
                        @php
                            $status = data_get($deployment, 'status');
                            $statusType = $status === 'in_progress' ? 'warning' : 'neutral';
                        @endphp
                        <a {{ wireNavigate() }} href="{{ data_get($deployment, 'deployment_url') }}"
                            class="grid min-h-14 grid-cols-[minmax(0,1fr)_minmax(8rem,.55fr)_8rem_2rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                            <span class="flex min-w-0 items-center gap-3">
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-dim">
                                    <x-reicon name="refresh" class="size-4" />
                                </span>
                                <span class="truncate text-[13px] font-semibold text-black dark:text-fg">
                                    {{ data_get($deployment, 'application_name') }}
                                </span>
                            </span>
                            <span class="truncate text-neutral-500 dark:text-fg-dim">{{ $serverName }}</span>
                            <x-status-badge :status="str($status)->headline()" :type="$statusType" />
                            <x-reicon name="arrow-right"
                                class="size-3.5 justify-self-end text-neutral-400 dark:text-fg-faint" />
                        </a>
                    @endforeach
                @endforeach

                <footer
                    class="flex min-h-11 items-center border-t border-neutral-200 px-4 text-[11px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                    {{ collect($deploymentsPerTagPerServer)->flatten(1)->count() }}
                    {{ Str::plural('deployment', collect($deploymentsPerTagPerServer)->flatten(1)->count()) }}
                </footer>
            </div>
        @endif
    </x-application.settings-section>
</div>
