<div>
    <x-slot:title>
        Keys & Tokens | Coolify
    </x-slot>

    <x-security.settings-layout>
        <x-application.settings-section title="Private keys"
            description="SSH keys used to connect servers and private repositories." flush>
        <x-slot:actions>
            @can('create', App\Models\PrivateKey::class)
                <x-modal-confirmation title="Confirm unused SSH Key Deletion?"
                    isErrorButton submitAction="cleanupUnusedKeys"
                    :actions="['All unused SSH keys (marked with unused) are permanently deleted.']"
                    :confirmWithText="false" :confirmWithPassword="false">
                    <x-slot:trigger>
                        <button type="button"
                            class="button whitespace-nowrap text-error! hover:text-error! dark:text-error!">
                            <span class="max-sm:hidden">Delete unused keys</span>
                            <span class="sm:hidden">Delete unused</span>
                        </button>
                    </x-slot:trigger>
                </x-modal-confirmation>

                <div x-data="{ dropdownOpen: false }" class="relative"
                    @click.outside="dropdownOpen = false" @keydown.escape.window="dropdownOpen = false">
                    <button type="button" @click="dropdownOpen = !dropdownOpen"
                        class="button whitespace-nowrap button-highlighted"
                        aria-haspopup="menu" :aria-expanded="dropdownOpen">
                        <x-reicon name="plus" class="size-3.5" />
                        <span class="max-sm:hidden">New private key</span>
                        <span class="sm:hidden">New key</span>
                        <x-reicon name="chevron-down" class="size-3 opacity-55" />
                    </button>

                    <div x-cloak x-show="dropdownOpen" x-transition.origin.top.right role="menu"
                        class="listbox-panel left-auto! right-0! z-[90]! w-52! min-w-52!">
                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                            wire:click="generatePrivateKey('ed25519')" @click="dropdownOpen = false"
                            role="menuitem">
                            <x-reicon name="keys" class="size-3.5 shrink-0 opacity-70" />
                            Generate ED25519
                        </button>
                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                            wire:click="generatePrivateKey('rsa')" @click="dropdownOpen = false"
                            role="menuitem">
                            <x-reicon name="keys" class="size-3.5 shrink-0 opacity-70" />
                            Generate RSA
                        </button>
                        <x-modal-input title="Add Private Key Manually">
                            <x-slot:content>
                                <button type="button" @click="dropdownOpen = false"
                                    class="listbox-option justify-start! gap-2.5!" role="menuitem">
                                    <x-reicon name="plus" class="size-3.5 shrink-0 opacity-70" />
                                    Add manually
                                </button>
                            </x-slot:content>
                            <livewire:security.private-key.create :modal_mode="true" />
                        </x-modal-input>
                    </div>
                </div>
            @endcan
        </x-slot:actions>


    @if ($privateKeys->isEmpty())
        <x-empty title="No private keys yet"
            description="Generate or add an SSH key to connect Coolify to servers and private repositories."
            icon-name="keys" />
    @else
        <div>
            <div class="grid grid-cols-[minmax(0,1fr)_7rem_1.75rem] items-center gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[13px] font-medium text-neutral-500 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_7rem_1.75rem] dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                <div class="pl-11">Private key</div>
                <div class="hidden sm:block">Description</div>
                <div class="text-center">Status</div>
                <div class="w-7"></div>
            </div>
            @foreach ($privateKeys as $key)
                @can('view', $key)
                    <x-modal-input title="Edit Private Key" isFullWidth :wireIgnore="false" :contentClicks="false"
                        class="border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                        <x-slot:content>
                    <div
                        class="grid min-h-14 w-full grid-cols-[minmax(0,1fr)_7rem_1.75rem] items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-neutral-50 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_7rem_1.75rem] dark:hover:bg-white/[0.025]"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                <x-reicon name="keys" class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                    {{ data_get($key, 'name') }}
                                </h3>
                            </div>
                        </div>
                        <p class="hidden truncate text-[12px] text-neutral-500 sm:block dark:text-fg-dim">
                            {{ $key->description ?: '-' }}
                        </p>
                        <div class="flex justify-center">
                            @if ($key->isInUse())
                                <x-status-badge label="In use" type="success" />
                            @else
                                <x-status-badge label="Unused" type="warning" />
                            @endif
                        </div>
                        <button type="button" class="icon-button" title="Edit private key"
                            aria-label="Edit {{ $key->name }}" @click="modalOpen=true">
                            <x-reicon name="settings" class="size-3.5" />
                        </button>
                    </div>
                        </x-slot:content>
                        <livewire:security.private-key.show :private_key_uuid="$key->uuid" :modalMode="true"
                            :key="'private-key-editor-'.$key->uuid" />
                    </x-modal-input>
                @else
                    <div class="grid min-h-14 cursor-not-allowed grid-cols-[minmax(0,1fr)_7rem_1.75rem] items-center gap-3 border-b border-neutral-200 px-4 py-2.5 opacity-65 last:border-b-0 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_7rem_1.75rem] dark:border-white/[0.07]"
                        title="You do not have permission to view this private key">
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-white text-neutral-400 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                                <x-reicon name="keys" class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                    {{ data_get($key, 'name') }}
                                </h3>
                            </div>
                        </div>
                        <p class="hidden truncate text-[12px] text-neutral-500 sm:block dark:text-fg-dim">{{ $key->description ?: '-' }}</p>
                        <div class="flex flex-wrap justify-center gap-2">
                            <x-status-badge label="View only" type="neutral" />
                            @if (!$key->isInUse())
                                <x-status-badge label="Unused" type="warning" />
                            @endif
                        </div>
                        <span class="size-7"></span>
                    </div>
                @endcan
            @endforeach
        </div>
    @endif
        </x-application.settings-section>

    </x-security.settings-layout>
</div>
