@props(['position' => 'left-0 top-full mt-2'])

{{-- Custom color picker popover: color field + light/dark mode in one panel.
     Renders inline (no own Alpine scope) so it uses the parent themeControls()
     state: pickerOpen, themeColor, customMode, previewThemeColor, saveThemeColor,
     setCustomMode. --}}
<div x-show="pickerOpen" x-cloak
    x-transition:enter="transition ease-out duration-150"
    x-transition:enter-start="opacity-0 -translate-y-1 scale-[0.98]"
    x-transition:enter-end="opacity-100 translate-y-0 scale-100"
    x-transition:leave="transition ease-in duration-100"
    x-transition:leave-start="opacity-100 translate-y-0 scale-100"
    x-transition:leave-end="opacity-0 -translate-y-1 scale-[0.98]"
    role="dialog" aria-label="Custom color"
    class="absolute z-[60] w-60 origin-top {{ $position }} rounded-xl border border-neutral-200 bg-white p-3 text-left shadow-dropdown dark:border-white/[0.1] dark:bg-panel">
    <div class="mb-2 text-[11px] font-semibold tracking-wide text-neutral-400 uppercase dark:text-fg-faint">
        Custom color
    </div>
    <label class="flex cursor-pointer items-center gap-2.5 rounded-lg border border-neutral-200 p-2 transition-colors hover:border-neutral-300 dark:border-white/[0.08] dark:hover:border-white/[0.14]">
        <span class="relative size-8 shrink-0 overflow-hidden rounded-md border border-black/10 shadow-sm dark:border-white/15">
            <span class="absolute inset-0" :style="`background: ${themeColor}`"></span>
            <input type="color" :value="themeColor" @input="previewThemeColor($event.target.value)"
                @change="saveThemeColor($event.target.value)" aria-label="Custom theme color"
                class="absolute inset-0 h-full w-full cursor-pointer opacity-0" />
        </span>
        <span class="flex min-w-0 flex-col">
            <span class="text-xs font-medium text-black dark:text-fg">Pick a color</span>
            <span class="text-[11px] tracking-wide text-neutral-500 uppercase dark:text-fg-dim" x-text="themeColor"></span>
        </span>
    </label>
    <div class="mt-2 grid grid-cols-2 gap-1 rounded-lg border border-neutral-200 p-0.5 dark:border-white/[0.08]"
        role="group" aria-label="Custom theme mode">
        <button type="button" @click="setCustomMode('light')" :aria-pressed="customMode === 'light'"
            class="rounded-md py-1.5 text-xs font-medium transition-colors"
            :class="customMode === 'light'
                ? 'bg-neutral-100 text-black shadow-sm dark:bg-white/[0.14] dark:text-fg'
                : 'text-neutral-500 hover:text-black dark:text-fg-dim dark:hover:text-fg'">
            Light
        </button>
        <button type="button" @click="setCustomMode('dark')" :aria-pressed="customMode === 'dark'"
            class="rounded-md py-1.5 text-xs font-medium transition-colors"
            :class="customMode === 'dark'
                ? 'bg-neutral-100 text-black shadow-sm dark:bg-white/[0.14] dark:text-fg'
                : 'text-neutral-500 hover:text-black dark:text-fg-dim dark:hover:text-fg'">
            Dark
        </button>
    </div>
</div>
