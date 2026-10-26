@props([
    'result' => [],
    'vulnerabilitiesUrl' => null,
    'deploymentUrl' => null,
    'showDeployAnyway' => false,
    'canDeployAnyway' => false,
    'updateTo' => null,
])

@php
    $isBlocked = (bool) data_get($result, 'blocked');
    $isSkipped = ! $isBlocked && filled(data_get($result, 'error'));
    $headline = (string) data_get($result, 'headline', '');
    $linkClasses = 'inline-flex items-center gap-0.5 font-semibold normal-case text-coollabs transition-colors hover:text-coollabs-100 dark:text-brand-light dark:hover:text-brand-light/80';
@endphp

@if ($isBlocked || $isSkipped)
    <div data-image-scan-gate {{ $attributes->class(['relative']) }} x-data="{
        open: false,
        panelStyle: 'position: fixed; visibility: hidden;',
        toggle() {
            if (this.open) {
                this.open = false;
                return;
            }
            this.panelStyle = 'position: fixed; visibility: hidden;';
            this.open = true;
            this.$nextTick(() => this.updatePosition());
        },
        updatePosition() {
            const trigger = this.$refs.trigger.getBoundingClientRect();
            const panel = this.$refs.panel.getBoundingClientRect();
            const padding = 8;
            const left = Math.max(padding, Math.min(trigger.left, window.innerWidth - panel.width - padding));
            const top = window.innerHeight - trigger.bottom - padding >= panel.height
                ? trigger.bottom + 6
                : Math.max(padding, trigger.top - panel.height - 6);
            this.panelStyle = `position: fixed; left: ${left}px; top: ${top}px;`;
        }
    }" @click.outside="open = false" @keydown.escape.window="open = false"
        x-on:resize.window="if (open) updatePosition()" x-on:scroll.window="if (open) updatePosition()">
        <x-status-badge as="button" x-ref="trigger" x-on:click="toggle()" aria-haspopup="dialog"
            x-bind:aria-expanded="open" :status="$isBlocked ? 'Blocked' : 'Not scanned'"
            :type="$isBlocked ? 'error' : 'warning'" class="hover:bg-neutral-200 dark:hover:bg-white/[0.12]" />

        <div x-ref="panel" x-show="open" x-cloak x-transition.opacity :style="panelStyle" role="dialog"
            aria-label="{{ $isBlocked ? 'Deploy blocked' : 'Image not scanned' }}"
            class="surface-popover z-[1100] w-[calc(100vw-2rem)] max-w-sm rounded-lg p-3 text-left">
            <div class="flex items-start gap-2.5">
                <span @class([
                    'flex size-7 shrink-0 items-center justify-center rounded-md',
                    'bg-red-100 text-red-700 dark:bg-error/10 dark:text-error' => $isBlocked,
                    'bg-amber-100 text-amber-700 dark:bg-warning/10 dark:text-warning' => $isSkipped,
                ])>
                    <x-reicon name="shield-alert" class="size-4" />
                </span>
                <div class="min-w-0 flex-1 whitespace-normal">
                    <p class="text-[13px] font-semibold leading-4 text-neutral-950 dark:text-fg">{{ $headline }}</p>
                    @if ($isBlocked && $updateTo)
                        <p class="mt-1 text-[11px] leading-4 text-neutral-600 dark:text-fg-dim">
                            A newer version fixes vulnerabilities.
                            <a href="{{ $vulnerabilitiesUrl }}" {{ wireNavigate() }} class="{{ $linkClasses }}">Update to {{ $updateTo }}</a>
                        </p>
                    @endif
                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[11px] leading-4">
                        @if ($vulnerabilitiesUrl)
                            <a href="{{ $vulnerabilitiesUrl }}" {{ wireNavigate() }} class="{{ $linkClasses }}">
                                Review vulnerabilities
                                <x-reicon name="arrow-right" class="size-2.5" />
                            </a>
                        @endif
                        @if ($deploymentUrl)
                            <a href="{{ $deploymentUrl }}" {{ wireNavigate() }} class="{{ $linkClasses }}">
                                Open deployment
                                <x-reicon name="arrow-right" class="size-2.5" />
                            </a>
                        @endif
                        @if ($isBlocked && $showDeployAnyway && $canDeployAnyway)
                            <x-forms.button class="normal-case!" wire:click="deployAnyway">
                                Deploy anyway
                            </x-forms.button>
                        @endif
                    </div>
                    @if ($isBlocked && $showDeployAnyway && ! $canDeployAnyway)
                        <p class="mt-2 text-[11px] leading-4 text-neutral-600 dark:text-fg-dim">
                            Ask someone with write access to deploy anyway.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif
