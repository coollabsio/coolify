<div class="application-settings-form w-full">
    <x-slot:title>
        Storages | Coolify
    </x-slot>

    <header class="mb-5 flex items-center justify-between gap-4">
        <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">S3 Storage</h1>
        <div class="shrink-0">
            @can('create', App\Models\S3Storage::class)
                <x-modal-input title="New S3 Storage" :closeOutside="false">
                    <x-slot:content>
                        <button type="button"
                            class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                            <x-reicon name="plus" class="size-3.5" />
                            New storage
                        </button>
                    </x-slot:content>
                    <livewire:storage.create />
                </x-modal-input>
            @endcan
        </div>
    </header>

    <p class="mb-4 text-[11px] text-neutral-500 dark:text-fg-faint">
        {{ $s3->count() }} {{ Str::plural('storage destination', $s3->count()) }} for backups
    </p>

    @if ($s3->isEmpty())
        <div
            class="flex min-h-80 flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-50 px-6 text-center dark:border-white/[0.1] dark:bg-white/[0.02]">
            <div
                class="mb-4 flex size-11 items-center justify-center rounded-xl border border-neutral-200 bg-white text-neutral-400 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-faint">
                <x-reicon name="storages" class="size-5" />
            </div>
            <h2 class="text-[15px] font-semibold">No S3 storage yet</h2>
            <p class="mt-1 max-w-sm text-[13px] text-neutral-500 dark:text-fg-dim">
                Add an S3-compatible destination to store backups outside your servers.
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($s3 as $storage)
                <a {{ wireNavigate() }} href="/storages/{{ $storage->uuid }}"
                    class="group flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                            <x-reicon name="storages" class="size-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                {{ $storage->name }}
                            </h2>
                            <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                {{ $storage->description ?: 'S3-compatible storage' }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-auto pt-4">
                        @if ($storage->is_usable)
                            <x-status-badge label="Ready" type="success" />
                        @else
                            <x-status-badge label="Not usable" type="error" />
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
