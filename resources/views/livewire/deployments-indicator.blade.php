{{-- Rendered twice: in the desktop sidebar footer and in the mobile top bar. `.visible` pauses
     polling for the copy hidden by the current breakpoint (a display:none element has no box). --}}
<div wire:poll.3000ms.visible x-data="{ expanded: false }" x-on:click.outside="expanded = false"
    x-on:keydown.escape.window="expanded = false" @class(['relative' => $variant === 'sidebar'])>
    @if ($this->deploymentCount > 0)
        @php
            $deploymentLabel = $this->deploymentCount.' '.Str::plural('deployment', $this->deploymentCount);
        @endphp
        {{-- Deployment list: opens beside the sidebar on desktop. On mobile the root is not positioned,
             so the list anchors to the sticky top bar and spans its width. --}}
        <div x-show="expanded" x-cloak x-transition.opacity.duration.150ms
            @class([
                'surface-popover absolute z-60 overflow-hidden rounded-xl',
                'bottom-0 left-full ml-5 w-[22rem]' => $variant === 'sidebar',
                'inset-x-4 top-full mt-2' => $variant === 'mobile',
            ])>
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

        @if ($variant === 'sidebar')
            <button type="button" x-on:click="expanded = !expanded" title="{{ $deploymentLabel }}"
                aria-label="Active deployments" :aria-expanded="expanded.toString()"
                class="menu-item w-full text-left text-coollabs dark:text-warning"
                :class="[collapsed && 'lg:justify-center lg:px-0', expanded && 'menu-item-active']">
                <span class="relative inline-flex">
                    <svg class="menu-item-icon animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                        aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                    <span x-show="collapsed" x-cloak
                        class="absolute -right-1.5 -top-1.5 flex min-w-3.5 items-center justify-center rounded-full bg-coollabs px-1 text-[9px] font-semibold leading-3.5 text-white dark:bg-warning dark:text-black">{{ $this->deploymentCount }}</span>
                </span>
                <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ $deploymentLabel }}</span>
            </button>
        @else
            <button type="button" x-on:click="expanded = !expanded" title="{{ $deploymentLabel }}"
                aria-label="Active deployments" :aria-expanded="expanded.toString()"
                class="flex h-9 items-center gap-1.5 rounded-md px-2 text-[13px] font-medium text-coollabs transition-colors hover:bg-neutral-100 dark:text-warning dark:hover:bg-white/[0.06]">
                <svg class="size-3.5 shrink-0 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                    aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                    </path>
                </svg>
                <span>{{ $this->deploymentCount }}</span>
            </button>
        @endif
    @endif
</div>
