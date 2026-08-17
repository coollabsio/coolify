<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @else
        @php
            $privateKeyOptions = $private_keys
                ->map(fn ($key) => ['value' => $key->id, 'label' => $key->name])
                ->values()
                ->all();
        @endphp

        <form wire:submit="submit">
            <x-application.settings-section title="Connect a server"
                description="Add an existing Linux server using its SSH connection details.">
                <x-slot:actions>
                    <button type="submit"
                        class="button button-highlighted">
                        Continue
                        <x-reicon name="arrow-right" class="size-3.5" />
                    </button>
                </x-slot:actions>

                <div class="mb-5">
                    <x-forms.input id="ip" label="IP address or domain" required
                        helper="For example 127.0.0.1 or server.example.com." />
                </div>

                <div class="mb-5">
                    <div class="flex items-end gap-3">
                        <div class="min-w-0 flex-1">
                            <x-forms.listbox id="private_key_id" label="Private key"
                                placeholder="Select a private key" :options="$privateKeyOptions" />
                        </div>
                        @can('create', App\Models\PrivateKey::class)
                            <div x-data="{ dropdownOpen: false }" class="relative shrink-0"
                                @click.outside="dropdownOpen = false"
                                @keydown.escape.window="dropdownOpen = false">
                                <button type="button" class="button" @click="dropdownOpen = !dropdownOpen"
                                    aria-haspopup="menu" :aria-expanded="dropdownOpen">
                                    <x-reicon name="plus" class="size-3.5" />
                                    New key
                                    <x-reicon name="chevron-down" class="size-3 opacity-55" />
                                </button>
                                <div x-cloak x-show="dropdownOpen" x-transition.origin.top.right role="menu"
                                    class="listbox-panel left-auto! right-0! z-[90]! w-52! min-w-52!">
                                    <button type="button" class="listbox-option justify-start! gap-2.5!"
                                        wire:click="generatePrivateKey('ed25519')"
                                        @click="dropdownOpen = false" role="menuitem">
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
                                        <livewire:security.private-key.create :modal_mode="true" from="server" />
                                    </x-modal-input>
                                </div>
                            </div>
                        @endcan
                    </div>
                </div>

                <div class="grid gap-4 border-t border-neutral-200 pt-4 lg:grid-cols-2 dark:border-white/[0.08]">
                    <x-forms.input id="name" label="Name" required />
                    <x-forms.input id="description" label="Description" />
                </div>

                <x-forms.collapsible class="mt-5 border-t border-neutral-200 pt-4 dark:border-white/[0.08]"
                    content-class="flex flex-col gap-4">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.input id="user" label="User" required
                            helper="Non-root SSH users are experimental." />
                        <x-forms.input type="number" id="port" label="Port" required />
                    </div>
                    <x-forms.listbox id="is_build_server"
                        helper="Build servers compile applications but do not host deployments. Enabling this makes the server build-only."
                        label="Use as a dedicated build server" :options="[
                            ['value' => false, 'label' => 'No'],
                            ['value' => true, 'label' => 'Yes'],
                        ]" />
                </x-forms.collapsible>
            </x-application.settings-section>
        </form>
    @endif
</div>
