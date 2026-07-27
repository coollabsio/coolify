<div>
    <x-slot:title>
        Cloud-Init Scripts | Coolify
    </x-slot>

    <x-security.navbar>
        <x-slot:actions>
            @can('create', App\Models\CloudInitScript::class)
                <x-modal-input title="New Cloud-Init Script">
                    <x-slot:content>
                        <button type="button"
                            class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                            <x-reicon name="plus" class="size-3.5" />
                            New script
                        </button>
                    </x-slot:content>
                    <livewire:security.cloud-init-script-form />
                </x-modal-input>
            @endcan
        </x-slot:actions>
    </x-security.navbar>

    <div class="application-settings-form">
        <x-application.settings-section title="Cloud-init scripts"
            description="Reusable initialization scripts for cloud servers." flush>
            @if ($scripts->isEmpty())
                <x-empty title="No cloud-init scripts"
                    description="Create a script to reuse it during server provisioning." size="sm">
                    <x-slot:icon>
                        <x-reicon name="file-content" class="size-6" />
                    </x-slot:icon>
                </x-empty>
            @else
                <div class="grid grid-cols-1 gap-3 p-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($scripts as $script)
                        @can('view', $script)
                            <a wire:key="cloud-init-script-{{ $script->id }}"
                                class="group flex min-h-28 min-w-0 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                                href="{{ route('security.cloud-init-scripts.show', ['cloud_init_script_uuid' => $script->uuid]) }}"
                                {{ wireNavigate() }}>
                                <div class="flex min-w-0 items-start gap-3">
                                    <div
                                        class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                        <x-reicon name="file-content" class="size-4" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h3
                                            class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                            {{ $script->name }}
                                        </h3>
                                        <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                                            Cloud-init script
                                        </p>
                                    </div>
                                </div>
                                <div class="mt-auto flex items-center justify-between pt-4">
                                    <span class="text-[10px] text-neutral-400 dark:text-fg-faint">
                                        Updated {{ $script->updated_at->diffForHumans() }}
                                    </span>
                                    <x-reicon name="arrow-right" class="size-3 text-neutral-400" />
                                </div>
                            </a>
                        @endcan
                    @endforeach
                </div>
            @endif
        </x-application.settings-section>
    </div>
</div>
