{{-- Rendered twice: in the desktop top bar and in the mobile top bar. `.visible` pauses
     polling for the copy hidden by the current breakpoint (a display:none element has no box). --}}
<div wire:poll.3000ms.visible x-data="{ expanded: false }" x-on:click.outside="expanded = false"
    x-on:keydown.escape.window="expanded = false" @class(['relative' => $variant === 'topbar'])>
    @if ($this->deploymentCount > 0 && ! $this->isOnlyCurrentApplicationDeploying)
        @php
            $deploymentLabel = $this->deploymentCount.' '.Str::plural('deployment', $this->deploymentCount);
        @endphp
        {{-- Deployment list: on desktop it opens below the button, aligned to its right edge.
             On mobile the root is not positioned, so the list anchors to the sticky top bar
             and spans its width. --}}
        <div x-show="expanded" x-cloak x-transition.opacity.duration.150ms
            @class([
                'surface-popover absolute top-full z-60 mt-2 overflow-hidden rounded-xl',
                'right-0 w-[22rem]' => $variant === 'topbar',
                'inset-x-4' => $variant === 'mobile',
            ])>
            <div class="max-h-96 overflow-y-auto p-1.5 scrollbar">
                @foreach ($this->deployments as $deployment)
                    @php
                        $deploymentStatus = $deployment->status === 'in_progress' ? 'In progress' : 'Queued';
                        $deploymentStatusType = $deployment->status === 'in_progress' ? 'warning' : 'neutral';
                        $deploymentDetails = collect([$deployment->server_name, $deployment->pull_request_id ? 'PR #'.$deployment->pull_request_id : null])->filter()->join(' · ');
                    @endphp
                    <a wire:key="indicator-deployment-{{ $deployment->id }}"
                        href="{{ $deployment->deployment_url }}" {{ wireNavigate() }}
                        class="flex items-center justify-between gap-3 rounded-md px-2.5 py-2 transition-colors hover:bg-neutral-50 hover:no-underline dark:hover:bg-raised">
                        <p class="min-w-0 truncate text-[13px]">
                            <span class="font-semibold text-black dark:text-fg">{{ $deployment->application_name }}</span>
                            <span class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                {{ $deploymentDetails }}
                            </span>
                        </p>
                        <x-status-badge :status="$deploymentStatus" :type="$deploymentStatusType" size="sm"
                            :spinning="$deployment->status === 'in_progress'" class="shrink-0" />
                    </a>
                @endforeach
            </div>
        </div>
        <button type="button" x-on:click="expanded = !expanded" title="{{ $deploymentLabel }}"
            aria-label="Active deployments" :aria-expanded="expanded.toString()"
            :class="expanded && 'bg-neutral-100 dark:bg-white/[0.06]'"
            class="flex h-8 items-center gap-1.5 rounded-md px-2.5 text-[13px] font-medium text-coollabs transition-colors hover:bg-neutral-100 dark:text-brand-light dark:hover:bg-white/[0.06]">
            {{-- A pulsing dot, not a spinner: the "Deploying…" button of the current resource already spins next to it. --}}
            <span class="relative flex size-2 shrink-0" aria-hidden="true">
                <span class="absolute inline-flex size-full rounded-full bg-current opacity-60 motion-safe:animate-ping"></span>
                <span class="relative inline-flex size-2 rounded-full bg-current"></span>
            </span>
            {{-- Trim the line box to the digit height so flex centers the digit, not the font ascent and descent. --}}
            <span class="leading-none tabular-nums [text-box:trim-both_cap_alphabetic]">{{ $this->deploymentCount }}</span>
        </button>
    @endif
</div>
