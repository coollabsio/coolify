<div wire:poll.3000ms x-on:livewire:navigated.window="
        $wire.updateShouldShowFromPath(window.location.pathname || '/')
    " x-data="{
    expanded: @entangle('expanded')
}" class="fixed bottom-0 left-0 z-60 mb-4 ml-4 transition-[left] duration-200"
    :class="collapsed ? 'lg:left-16' : 'lg:left-56'">
    @if ($this->shouldShow && $this->deploymentCount > 0)
        <div class="relative">
            {{-- Expanded deployment list (above the pill) --}}
            <div x-show="expanded" x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="translate-y-2" x-transition:enter-end="translate-y-0"
                x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-y-0"
                x-transition:leave-end="translate-y-2" x-cloak
                class="absolute bottom-full mb-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-xl"
                style="background: var(--coollabs-elevated); box-shadow: 0 0 0 1px var(--coollabs-line), var(--shadow-modal);">
                <div class="max-h-96 space-y-1 overflow-y-auto p-2 scrollbar">
                    @foreach ($this->deployments as $deployment)
                        @php
                            $deploymentStatus = $deployment->status === 'in_progress' ? 'In progress' : 'Queued';
                            $deploymentStatusType = $deployment->status === 'in_progress' ? 'warning' : 'neutral';
                            $projectName = $deployment->application?->environment?->project?->name;
                            $environmentName = $deployment->application?->environment?->name;
                            $environmentPath = collect([$projectName, $environmentName])->filter()->join(' / ');
                        @endphp
                        <a wire:key="indicator-deployment-{{ $deployment->id }}"
                            href="{{ $deployment->deployment_url }}" {{ wireNavigate() }}
                            class="flex items-start gap-3 rounded-lg border border-transparent p-3 transition-colors hover:border-neutral-200 hover:bg-neutral-50 hover:no-underline dark:border-coolgray-300 dark:hover:border-coolgray-400 dark:hover:bg-raised">
                            <div
                                class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-coollabs dark:border-coolgray-300 dark:bg-raised dark:text-warning">
                                @if ($deployment->status === 'in_progress')
                                    <svg class="size-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none"
                                        viewBox="0 0 24 24" aria-hidden="true">
                                        <circle class="opacity-25" cx="12" cy="12" r="10"
                                            stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor"
                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                        </path>
                                    </svg>
                                @else
                                    <x-reicon name="time-back" class="size-3.5 text-neutral-500 dark:text-fg-dim" />
                                @endif
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="truncate text-[13px] font-semibold text-black dark:text-fg">
                                        {{ $deployment->application_name }}
                                    </p>
                                    <x-status-badge :status="$deploymentStatus" :type="$deploymentStatusType"
                                        class="shrink-0" />
                                </div>

                                @if ($environmentPath !== '')
                                    <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                        {{ $environmentPath }}
                                    </p>
                                @endif

                                <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                    {{ $deployment->server_name ?: '-' }}
                                    @if ($deployment->pull_request_id)
                                        <span class="px-1 text-neutral-300 dark:text-fg-faint">·</span>
                                        PR #{{ $deployment->pull_request_id }}
                                    @endif
                                </p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- Collapsed pill --}}
            <button type="button" @click="expanded = !expanded"
                class="flex items-center gap-2 rounded-xl border border-neutral-200 bg-white px-3.5 py-2 text-sm font-medium text-neutral-800 transition-colors hover:bg-neutral-50 dark:border-coolgray-300 dark:bg-surface dark:text-fg dark:hover:bg-raised"
                style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal);"
                :aria-expanded="expanded.toString()" aria-label="Active deployments">
                <svg class="loading-indicator size-3.5 shrink-0 animate-spin"
                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                        stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                    </path>
                </svg>

                <span>
                    {{ $this->deploymentCount }} {{ Str::plural('deployment', $this->deploymentCount) }}
                </span>

                <span class="inline-flex shrink-0 text-neutral-400 transition-transform duration-200 dark:text-fg-faint"
                    :class="{ 'rotate-180': expanded }">
                    <x-reicon name="chevron-down" class="size-3.5" />
                </span>
            </button>
        </div>
    @endif
</div>
