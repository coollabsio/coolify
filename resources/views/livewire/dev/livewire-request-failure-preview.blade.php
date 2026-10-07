<div class="mx-auto flex min-h-screen w-full max-w-3xl flex-col gap-6 px-6 py-12">
    <div class="flex flex-col gap-2">
        <p class="text-xs font-semibold uppercase tracking-wider text-coollabs">Development tool</p>
        <h1 class="text-2xl font-semibold text-neutral-950 dark:text-white">Livewire request failure preview</h1>
        <p class="text-sm leading-6 text-neutral-600 dark:text-fg-dim">
            Each button returns proxy-style HTML from a failed Livewire request. The page should remain visible and
            Coolify should show a toast instead of Livewire's raw response modal.
        </p>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        @foreach ($statuses as $status)
            <x-forms.button wire:click="fail({{ $status }})" class="justify-between">
                <span>{{ in_array($status, [504, 522, 524], true) ? 'Gateway timeout' : 'Proxy unavailable' }}</span>
                <span class="font-mono text-xs text-neutral-500 dark:text-fg-faint">{{ $status }}</span>
            </x-forms.button>
        @endforeach
    </div>

    <p class="text-xs text-neutral-500 dark:text-fg-faint">
        This route is registered only when <code class="font-mono">APP_ENV</code> is local or testing.
    </p>
</div>
