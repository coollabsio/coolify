@props([
    'action' => 'reopenDeployment',
    'label' => 'Deploying',
])

{{-- Persistent affordance shown while a deploy/start is running. Re-opens the
     live log dialog (the process runs server-side even after the modal closes).
     Uses the Alpine $wire magic (not wire:click) so it also works when the
     button is teleported to the desktop action HUD, outside the Livewire root. --}}
<button type="button" x-on:click="$wire.{{ $action }}()"
    {{ $attributes->class('inline-flex shrink-0 items-center gap-1.5 rounded-md px-2 py-1 text-[11px] font-medium ring-1 transition-colors bg-coollabs/10 text-coollabs ring-coollabs/25 hover:bg-coollabs/15 dark:bg-warning/15 dark:text-warning dark:ring-warning/25 dark:hover:bg-warning/20') }}
    title="View the running deployment log">
    <svg class="size-3 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" opacity="0.25" />
        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
    </svg>
    <span>{{ $label }}…</span>
    <span class="opacity-70">View log</span>
</button>
