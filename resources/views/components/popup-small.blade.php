@props([
    'title' => 'Default title',
    'description' => 'Default description',
    'compactAfter' => null,
    'compactStorageKey' => null,
    'compactStoragePrefix' => null,
])

<template x-teleport="body">
<div x-data="{
        bannerVisible: true,
        compact: false,
        iconOnly: false,
        storageKey: @js($compactStorageKey),
        storagePrefix: @js($compactStoragePrefix),
        init() {
            if (this.storagePrefix) {
                Object.keys(localStorage)
                    .filter(key => key.startsWith(this.storagePrefix) && key !== this.storageKey)
                    .forEach(key => localStorage.removeItem(key));
            }

            const storedState = this.storageKey ? localStorage.getItem(this.storageKey) : null;
            this.iconOnly = storedState === 'icon';
            this.compact = this.iconOnly || storedState === 'compact';

            if (!this.compact && {{ $compactAfter ?? 'false' }}) {
                setTimeout(() => {
                    if (this.iconOnly) return;
                    this.compact = true;
                    if (this.storageKey) localStorage.setItem(this.storageKey, 'compact');
                }, {{ $compactAfter ?? 0 }});
            }
        },
        minimizeToIcon() {
            this.iconOnly = true;
            this.compact = true;
            if (this.storageKey) localStorage.setItem(this.storageKey, 'icon');
        },
        restore() {
            if (!this.compact && !this.iconOnly) return;
            this.iconOnly = false;
            this.compact = false;
            if (this.storageKey) localStorage.setItem(this.storageKey, 'compact');
        },
    }"
    x-show="bannerVisible" x-cloak
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="translate-y-3 opacity-0"
    x-transition:enter-end="translate-y-0 opacity-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="translate-y-0 opacity-100"
    x-transition:leave-end="translate-y-3 opacity-0"
    class="fixed bottom-4 right-4 z-999"
    :class="compact ? 'w-auto max-w-[calc(100%-2rem)]' : 'w-[calc(100%-2rem)] max-w-sm'">
    <div class="relative flex items-start gap-2.5 rounded-lg p-3 pr-10"
        :class="compact ? (iconOnly ? 'cursor-pointer p-2! pr-2!' : 'cursor-pointer') : ''" @click="restore()"
        style="background: var(--coollabs-elevated); box-shadow: 0 0 0 1px var(--coollabs-line), var(--shadow-modal);">
        @isset($icon)
            <div
                class="flex size-7 shrink-0 items-center justify-center rounded-md bg-amber-100 text-amber-700 dark:bg-warning/10 dark:text-warning">
                {{ $icon }}
            </div>
        @endisset

        <div x-show="!iconOnly" class="min-w-0 flex-1">
            <h4 class="text-[13px] font-semibold leading-4 text-neutral-950 dark:text-fg">
                {{ $title }}
            </h4>
            <div x-show="!compact" x-transition.opacity
                class="mt-0.5 text-[11px] leading-4 text-neutral-600 dark:text-fg-dim">
                {{ $description }}
            </div>
        </div>

        <button x-show="!iconOnly" type="button" @click.stop="minimizeToIcon()" aria-label="Minimize warning"
            class="absolute right-2 top-2 flex size-6 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-black/5 hover:text-neutral-700 dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
            <x-reicon name="x" class="size-3.5" />
        </button>
    </div>
</div>
</template>
