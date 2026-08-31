<div>
    <x-slot:title>
        Cloud-Init Scripts | Coolify
    </x-slot>

    <x-security.settings-layout>
        <x-application.settings-section title="Cloud-init scripts"
            description="Reusable initialization scripts for cloud servers." flush>
        <x-slot:actions>
            @can('create', App\Models\CloudInitScript::class)
                <x-modal-input title="New Cloud-Init Script">
                    <x-slot:content>
                        <button type="button"
                            class="button button-highlighted">
                            <x-reicon name="plus" class="size-3.5" />
                            New script
                        </button>
                    </x-slot:content>
                    <livewire:security.cloud-init-script-form />
                </x-modal-input>
            @endcan
        </x-slot:actions>


            @if ($scripts->isEmpty())
                <x-empty title="No cloud-init scripts"
                    description="Create a script to reuse it during server provisioning."
                    icon-name="file-content" size="sm" />
            @else
                <div>
                    <div class="grid grid-cols-[minmax(0,1fr)_12rem_1.75rem] items-center gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[13px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                        <div class="pl-11">Script</div>
                        <div>Last updated</div>
                        <div class="w-7"></div>
                    </div>
                    @foreach ($scripts as $script)
                        @can('view', $script)
                            <x-modal-input title="Edit Cloud-Init Script" isFullWidth :wireIgnore="false" :contentClicks="false"
                                wire:key="cloud-init-script-{{ $script->id }}"
                                class="border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                                <x-slot:content>
                            <div
                                class="grid min-h-14 w-full grid-cols-[minmax(0,1fr)_12rem_1.75rem] items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-neutral-50 dark:hover:bg-white/[0.025]"
                            >
                                <div class="flex min-w-0 items-center gap-3">
                                    <div
                                        class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                        <x-reicon name="file-content" class="size-4" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h3
                                            class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                            {{ $script->name }}
                                        </h3>
                                    </div>
                                </div>
                                <div class="flex items-center">
                                    <span class="text-[10px] text-neutral-400 dark:text-fg-faint">
                                        Updated {{ $script->updated_at->diffForHumans() }}
                                    </span>
                                </div>
                                <button type="button" class="icon-button" title="Edit cloud-init script"
                                    aria-label="Edit {{ $script->name }}" @click="modalOpen=true">
                                    <x-reicon name="settings" class="size-3.5" />
                                </button>
                            </div>
                                </x-slot:content>
                                <livewire:security.cloud-init-script.show :cloud_init_script_uuid="$script->uuid"
                                    :modalMode="true" :key="'cloud-init-editor-'.$script->uuid" />
                            </x-modal-input>
                        @endcan
                    @endforeach
                </div>
            @endif
        </x-application.settings-section>

    </x-security.settings-layout>
</div>
