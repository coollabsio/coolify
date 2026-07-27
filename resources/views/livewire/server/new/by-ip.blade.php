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
                        class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                        Continue
                        <x-reicon name="arrow-right" class="size-3.5" />
                    </button>
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input id="name" label="Name" required />
                    <x-forms.input id="description" label="Description" />
                </div>

                <div class="mt-5 grid gap-4 border-t border-neutral-200 pt-4 lg:grid-cols-3 dark:border-white/[0.08]">
                    <x-forms.input id="ip" label="IP address or domain" required
                        helper="For example 127.0.0.1 or server.example.com." />
                    <x-forms.input id="user" label="User" required
                        helper="Non-root SSH users are experimental." />
                    <x-forms.input type="number" id="port" label="Port" required />
                </div>

                <div class="mt-5 grid items-end gap-4 border-t border-neutral-200 pt-4 lg:grid-cols-2 dark:border-white/[0.08]">
                    <x-forms.listbox id="private_key_id" label="Private key"
                        placeholder="Select a private key" :options="$privateKeyOptions" />

                    <div class="flex items-center justify-between gap-3">
                        <x-forms.checkbox id="is_build_server"
                            helper="Build servers compile applications but do not host deployments."
                            label="Use as a build server" />

                        @can('create', App\Models\PrivateKey::class)
                            <div x-data="{ dropdownOpen: false }" class="relative shrink-0"
                                @click.outside="dropdownOpen = false">
                                <button type="button" class="button" @click="dropdownOpen = !dropdownOpen">
                                    <x-reicon name="plus" class="size-3.5" />
                                    New key
                                </button>
                                <div x-cloak x-show="dropdownOpen" x-transition.origin.top.right
                                    class="absolute right-0 top-9 z-50 w-52 rounded-lg border border-neutral-200 bg-white p-1 shadow-modal dark:border-white/[0.1] dark:bg-raised">
                                    <button type="button"
                                        class="flex h-8 w-full items-center gap-2 rounded-md px-2 text-left text-[12px] text-neutral-600 hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                        wire:click="generatePrivateKey('ed25519')"
                                        @click="dropdownOpen = false">
                                        <x-reicon name="keys" class="size-3.5" />
                                        Generate ED25519
                                    </button>
                                    <button type="button"
                                        class="flex h-8 w-full items-center gap-2 rounded-md px-2 text-left text-[12px] text-neutral-600 hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                        wire:click="generatePrivateKey('rsa')" @click="dropdownOpen = false">
                                        <x-reicon name="keys" class="size-3.5" />
                                        Generate RSA
                                    </button>
                                    <x-modal-input title="Add Private Key Manually">
                                        <x-slot:content>
                                            <button type="button" @click="dropdownOpen = false"
                                                class="flex h-8 w-full items-center gap-2 rounded-md px-2 text-left text-[12px] text-neutral-600 hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                                                <x-reicon name="plus" class="size-3.5" />
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
            </x-application.settings-section>
        </form>
    @endif
</div>
