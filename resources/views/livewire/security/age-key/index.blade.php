<div>
    <x-slot:title>
        Keys & Tokens | Coolify
    </x-slot>

    <x-security.settings-layout>
        <x-application.settings-section title="Age keys"
            description="Public keys used to encrypt database backups with age." flush>
        <x-slot:actions>
            @can('create', App\Models\AgeKey::class)
                <div x-data="{ dropdownOpen: false }" class="relative"
                    @click.outside="dropdownOpen = false" @keydown.escape.window="dropdownOpen = false">
                    <button type="button" @click="dropdownOpen = !dropdownOpen"
                        class="button whitespace-nowrap button-highlighted"
                        aria-haspopup="menu" :aria-expanded="dropdownOpen">
                        <x-reicon name="plus" class="size-3.5" />
                        <span class="max-sm:hidden">New age key</span>
                        <span class="sm:hidden">New key</span>
                        <x-reicon name="chevron-down" class="size-3 opacity-55" />
                    </button>

                    <div x-cloak x-show="dropdownOpen" x-transition.origin.top.right role="menu"
                        class="listbox-panel left-auto! right-0! z-[90]! w-52! min-w-52!">
                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                            wire:click="generateAgeKey" @click="dropdownOpen = false"
                            role="menuitem">
                            <x-reicon name="keys" class="size-3.5 shrink-0 opacity-70" />
                            Generate new key
                        </button>
                        <x-modal-input title="Add Age Public Key">
                            <x-slot:content>
                                <button type="button" @click="dropdownOpen = false"
                                    class="listbox-option justify-start! gap-2.5!" role="menuitem">
                                    <x-reicon name="plus" class="size-3.5 shrink-0 opacity-70" />
                                    Add existing public key
                                </button>
                            </x-slot:content>
                            <livewire:security.age-key.create :modal_mode="true" />
                        </x-modal-input>
                    </div>
                </div>
            @endcan
        </x-slot:actions>

    @if ($generatedPublicKey)
        <div class="mb-5 flex flex-col gap-3 rounded-lg border border-amber-500/50 bg-amber-500/5 p-4">
            <p class="text-sm font-medium">Save this private key now — it will not be shown again</p>
            <p class="text-xs text-neutral-500 dark:text-fg-dim">
                Coolify does not store the private key. Copy it somewhere safe (a password manager,
                for example) before continuing — without it, backups encrypted with this key can
                never be decrypted.
            </p>
            <div>
                <label class="mb-1.5 block text-sm font-medium">Private key</label>
                <textarea readonly rows="2" onclick="this.select()"
                    class="input scrollbar font-mono text-xs">{{ $generatedPrivateKey }}</textarea>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium">Public key</label>
                <input readonly value="{{ $generatedPublicKey }}" class="input font-mono text-xs" />
            </div>
            <x-forms.input wire:model="generatedName" label="Name" placeholder="e.g. Production backups" />
            <div class="flex items-center gap-2">
                <x-forms.button type="button" wire:click="confirmGeneratedAgeKeySaved"
                    wire:loading.attr="disabled" wire:target="confirmGeneratedAgeKeySaved"
                    isHighlighted>I've saved the private key</x-forms.button>
                <x-forms.button type="button" wire:click="discardGeneratedAgeKey">Cancel</x-forms.button>
            </div>
        </div>
    @endif

    @if ($ageKeys->isEmpty())
        <x-empty title="No age keys yet"
            description="Generate or add an age public key to enable encrypted database backups."
            icon-name="keys" />
    @else
        <div>
            <div class="grid grid-cols-[minmax(0,1fr)_7rem_1.75rem] items-center gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[13px] font-medium text-neutral-500 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_7rem_1.75rem] dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                <div class="pl-11">Age key</div>
                <div class="hidden sm:block">Description</div>
                <div class="text-center">Status</div>
                <div class="w-7"></div>
            </div>
            @foreach ($ageKeys as $key)
                @can('view', $key)
                    <div wire:key="age-key-{{ $key->id }}"
                        class="border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
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
                        <button type="button" class="icon-button" title="Edit age key"
                            aria-label="Edit {{ $key->name }}"
                            @click="$dispatch('open-age-key-editor', { name: @js($key->name), description: @js($key->description ?? '') })"
                            wire:click="openEditor('{{ $key->uuid }}')">
                            <x-reicon name="settings" class="size-3.5" />
                        </button>
                    </div>
                    </div>
                @else
                    <div class="grid min-h-14 cursor-not-allowed grid-cols-[minmax(0,1fr)_7rem_1.75rem] items-center gap-3 border-b border-neutral-200 px-4 py-2.5 opacity-65 last:border-b-0 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_7rem_1.75rem] dark:border-white/[0.07]"
                        title="You do not have permission to view this age key">
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-white text-neutral-400 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
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

    <x-modal-input title="Edit Age Key" :wireIgnore="false" :contentClicks="false"
        @open-age-key-editor.window="modalOpen=true; $nextTick(() => { $refs.loadingAgeKeyName.value = $event.detail.name; $refs.loadingAgeKeyDescription.value = $event.detail.description })">
        <x-slot:content><span class="hidden" aria-hidden="true"></span></x-slot:content>
        <div wire:loading.flex wire:target="openEditor" aria-label="Loading age key editor"
            class="w-full flex-col gap-4">
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input label="Name" required x-ref="loadingAgeKeyName" />
                <x-forms.input label="Description" x-ref="loadingAgeKeyDescription" />
                <div class="lg:col-span-2">
                    <x-forms.input label="Public key" loading />
                </div>
            </div>
            <div class="flex items-center justify-between gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                <x-forms.button disabled isError>Delete</x-forms.button>
                <x-forms.button disabled isHighlighted>Save changes</x-forms.button>
            </div>
        </div>
        @if ($selectedAgeKeyUuid)
            <div wire:loading.remove wire:target="openEditor">
                <livewire:security.age-key.show :age_key_uuid="$selectedAgeKeyUuid" :modalMode="true"
                    :key="'age-key-editor-'.$selectedAgeKeyUuid" />
            </div>
        @endif
    </x-modal-input>
        </x-application.settings-section>

    </x-security.settings-layout>
</div>
