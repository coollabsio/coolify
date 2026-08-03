<div>
    <x-slot:title>
        Keys & Tokens | Coolify
    </x-slot>

    <x-security.navbar>
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
                        class="button whitespace-nowrap bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!"
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
                            <livewire:security.private-key.create />
                        </x-modal-input>
                    </div>
                </div>
            @endcan
        </x-slot:actions>
    </x-security.navbar>

    @if ($privateKeys->isEmpty())
        <x-empty title="No private keys yet"
            description="Generate or add an SSH key to connect Coolify to servers and private repositories."
            icon-name="keys" />
    @else
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($privateKeys as $key)
                @can('view', $key)
                    <a class="group flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                        href="{{ route('security.private-key.show', ['private_key_uuid' => data_get($key, 'uuid')]) }}"
                        {{ wireNavigate() }}>
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                <x-reicon name="keys" class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                    {{ data_get($key, 'name') }}
                                </h3>
                                <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                    {{ $key->description ?: 'SSH private key' }}
                                </p>
                            </div>
                        </div>
                        <div class="mt-auto pt-4">
                            @if ($key->isInUse())
                                <x-status-badge label="In use" type="success" />
                            @else
                                <x-status-badge label="Unused" type="warning" />
                            @endif
                        </div>
                    </a>
                @else
                    <div class="flex min-h-28 cursor-not-allowed flex-col rounded-xl border border-neutral-200 bg-neutral-50 p-3 opacity-65 dark:border-white/[0.08] dark:bg-white/[0.015]"
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
                                <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                    {{ $key->description ?: 'SSH private key' }}
                                </p>
                            </div>
                        </div>
                        <div class="mt-auto flex flex-wrap gap-2 pt-4">
                            <x-status-badge label="View only" type="neutral" />
                            @if (!$key->isInUse())
                                <x-status-badge label="Unused" type="warning" />
                            @endif
                        </div>
                    </div>
                @endcan
            @endforeach
        </div>
    @endif
</div>
