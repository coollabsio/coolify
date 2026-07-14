<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @else
        <form class="flex flex-col w-full gap-2" wire:submit='submit'>
            <div class="flex w-full gap-2 flex-wrap sm:flex-nowrap">
                <x-forms.input id="name" label="Name" required />
                <x-forms.input id="description" label="Description" />
            </div>
            <div class="flex gap-2 flex-wrap sm:flex-nowrap">
                <x-forms.input id="ip" label="IP Address/Domain" required
                    helper="An IP Address (127.0.0.1) or domain (example.com)." />
                <x-forms.input type="number" id="port" label="Port" required />
            </div>
            <x-forms.input id="user" label="User" required />
            <div class="text-xs dark:text-warning text-coollabs ">Non-root user is experimental: <a
                    class="font-bold underline" target="_blank"
                    href="https://coolify.io/docs/knowledge-base/server/non-root-user">docs</a>.</div>
            <div class="flex items-end gap-2">
                <div class="grow">
                    <x-forms.select label="Private Key" id="private_key_id">
                        <option disabled>Select a private key</option>
                        @foreach ($private_keys as $key)
                            <option value="{{ $key->id }}">{{ $key->name }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
                @can('create', App\Models\PrivateKey::class)
                    <div x-data="{ dropdownOpen: false }" class="relative w-fit" @click.outside="dropdownOpen = false">
                        <x-forms.button isHighlighted @click="dropdownOpen = !dropdownOpen" type="button">
                            + Add
                            <svg class="w-4 h-4 ml-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                            </svg>
                        </x-forms.button>

                        <div x-show="dropdownOpen" @click.away="dropdownOpen=false" x-transition:enter="ease-out duration-200"
                            x-transition:enter-start="-translate-y-2" x-transition:enter-end="translate-y-0"
                            class="absolute right-0 top-0 z-50 mt-10 min-w-max" x-cloak>
                            <div
                                class="p-1 mt-1 bg-white border rounded-sm shadow-sm dark:bg-coolgray-200 dark:border-coolgray-300 border-neutral-300">
                                <div class="flex flex-col gap-1">
                                    <a class="dropdown-item" wire:click="generatePrivateKey('ed25519')"
                                        @click="dropdownOpen = false">
                                        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        Generate ED25519
                                    </a>
                                    <a class="dropdown-item" wire:click="generatePrivateKey('rsa')" @click="dropdownOpen = false">
                                        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        Generate RSA
                                    </a>
                                    <x-modal-input title="Add Private Key Manually">
                                        <x-slot:content>
                                            <div class="dropdown-item" @click="dropdownOpen = false">
                                                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M12 4.5v15m7.5-7.5h-15" />
                                                </svg>
                                                Add manually
                                            </div>
                                        </x-slot:content>
                                        <livewire:security.private-key.create :modal_mode="true" from="server" />
                                    </x-modal-input>
                                </div>
                            </div>
                        </div>
                    </div>
                @endcan
            </div>
            <div class="">
                <x-forms.checkbox instantSave type="checkbox" id="is_build_server"
                    helper="Build servers are used to build your applications, so you cannot deploy applications to it."
                    label="Use it as a build server?" />
            </div>
            <x-forms.button type="submit">
                Continue
            </x-forms.button>
        </form>
    @endif
</div>