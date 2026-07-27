<div>
    <x-slot:title>
        Keys & Tokens | Coolify
    </x-slot>

    <x-security.navbar>
        <x-slot:actions>
            @can('create', App\Models\PrivateKey::class)
                <div class="flex flex-wrap items-center gap-2">
                    <x-modal-confirmation title="Confirm unused SSH Key Deletion?"
                        buttonTitle="Delete unused keys" isErrorButton submitAction="cleanupUnusedKeys"
                        :actions="['All unused SSH keys (marked with unused) are permanently deleted.']"
                        :confirmWithText="false" :confirmWithPassword="false" />

                    <div x-data="{ dropdownOpen: false }" class="relative"
                        @click.outside="dropdownOpen = false">
                        <button type="button" @click="dropdownOpen = !dropdownOpen"
                            class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!"
                            :aria-expanded="dropdownOpen">
                            <x-reicon name="plus" class="size-3.5" />
                            New private key
                        </button>

                        <div x-cloak x-show="dropdownOpen" x-transition.origin.top.right
                            class="absolute top-9 right-0 z-50 w-52 rounded-lg border border-neutral-200 bg-white p-1 shadow-modal dark:border-white/[0.1] dark:bg-raised">
                            <button type="button" class="listbox-option justify-start!"
                                wire:click="generatePrivateKey('ed25519')" @click="dropdownOpen = false">
                                <x-reicon name="keys" class="size-3.5 opacity-70" />
                                Generate ED25519
                            </button>
                            <button type="button" class="listbox-option justify-start!"
                                wire:click="generatePrivateKey('rsa')" @click="dropdownOpen = false">
                                <x-reicon name="keys" class="size-3.5 opacity-70" />
                                Generate RSA
                            </button>
                            <x-modal-input title="Add Private Key Manually">
                                <x-slot:content>
                                    <button type="button" @click="dropdownOpen = false"
                                        class="listbox-option justify-start!">
                                        <x-reicon name="plus" class="size-3.5 opacity-70" />
                                        Add manually
                                    </button>
                                </x-slot:content>
                                <livewire:security.private-key.create />
                            </x-modal-input>
                        </div>
                    </div>
                </div>
            @endcan
        </x-slot:actions>
    </x-security.navbar>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-[15px]! leading-5! font-semibold!">Private keys</h2>
            <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                {{ $privateKeys->count() }} {{ Str::plural('SSH key', $privateKeys->count()) }} available to this team
            </p>
        </div>

    </div>

    @if ($privateKeys->isEmpty())
        <div
            class="flex min-h-80 flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-50 px-6 text-center dark:border-white/[0.1] dark:bg-white/[0.02]">
            <div
                class="mb-4 flex size-11 items-center justify-center rounded-xl border border-neutral-200 bg-white text-neutral-400 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-faint">
                <x-reicon name="keys" class="size-5" />
            </div>
            <h2 class="text-[15px] font-semibold">No private keys yet</h2>
            <p class="mt-1 max-w-sm text-[13px] text-neutral-500 dark:text-fg-dim">
                Generate or add an SSH key to connect Coolify to servers and private repositories.
            </p>
        </div>
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
