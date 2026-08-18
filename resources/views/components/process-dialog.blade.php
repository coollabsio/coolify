@props([
    'closeWithX' => false,
    'mobileFullscreen' => false,
    'open' => false,
    'size' => 'lg',
])

@php
    $panelWidth = match ($size) {
        'md' => 'min-w-0 w-full max-w-2xl sm:min-w-[28rem]',
        'xl' => 'min-w-0 w-full max-w-5xl sm:min-w-[36rem] lg:min-w-[48rem]',
        default => 'min-w-0 w-full max-w-4xl sm:min-w-[32rem] lg:min-w-[42rem]',
    };
@endphp

{{-- `contents` so event-only shells (no trigger in the default slot) do not leave a
     ghost layout box above layer-2 tabs. Trigger buttons in the slot still flow
     into the parent as if unwrapped. --}}
<div x-data="{
        processDialogOpen: @js($open)
    }"
    x-init="$watch('processDialogOpen', value => {
        if (!value) {
            $dispatch('processDialogClosed')
        }
    })"
    {{ $attributes->merge(['class' => 'contents']) }}>
    {{ $slot }}
    <template x-teleport="body">
        <div x-show="processDialogOpen" x-cloak class="relative z-99"
            @if (! $closeWithX) @keydown.window.escape="processDialogOpen = false" @endif>
            <div x-show="processDialogOpen"
                x-transition:enter="ease-out duration-150"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-100"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @if (! $closeWithX) @click="processDialogOpen = false" @endif
                class="fixed inset-0 bg-black/50 backdrop-blur-[2px] dark:bg-black/60"></div>

            <div class="fixed inset-0 overflow-y-auto">
                <div @if (! $closeWithX) @click.self="processDialogOpen = false" @endif
                    @class([
                        'flex min-h-full items-center justify-center',
                        'p-4 sm:p-6' => ! $mobileFullscreen,
                        'p-0 sm:p-6' => $mobileFullscreen,
                    ])>
                    <div x-show="processDialogOpen"
                        x-trap.inert.noscroll="processDialogOpen"
                        x-transition:enter="ease-out duration-150"
                        x-transition:enter-start="opacity-0 translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 translate-y-2 sm:scale-95"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="process-dialog-title"
                        @class([
                            'application-settings-section application-settings-form process-dialog relative flex flex-col overflow-hidden',
                            'process-dialog-mobile-fullscreen' => $mobileFullscreen,
                            $panelWidth,
                            // Fixed shell size so empty “waiting for process” state does not collapse.
                            'min-h-[min(70dvh,28rem)] h-[min(85dvh,52rem)] max-h-[calc(100dvh-2rem)]',
                        ])
                        style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                        <header class="flex-nowrap!">
                            <h3 id="process-dialog-title" class="min-w-0 flex-1 truncate">
                                {{ $title }}
                            </h3>
                            <button type="button" @click="processDialogOpen = false"
                                class="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-neutral-500 outline-0 transition-colors hover:bg-neutral-100 hover:text-black focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                aria-label="Close">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>

                        <div class="application-settings-section-body process-dialog-body flex min-h-0 flex-1 flex-col overflow-hidden p-3 sm:p-4"
                            style="-webkit-overflow-scrolling: touch;">
                            {{ $content }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
