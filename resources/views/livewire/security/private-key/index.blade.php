<div>
    <x-security.navbar />
    <div class="flex items-center gap-2 pb-4">
        <h2>Private Keys</h2>
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
                    class="absolute top-0 z-50 mt-10 min-w-max" x-cloak>
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
                                <livewire:security.private-key.create />
                            </x-modal-input>
                        </div>
                    </div>
                </div>
            </div>
        @endcan
        @can('create', App\Models\PrivateKey::class)
            <x-modal-confirmation title="Confirm unused SSH Key Deletion?" buttonTitle="Delete unused SSH Keys" isErrorButton
                submitAction="cleanupUnusedKeys" :actions="['All unused SSH keys (marked with unused) are permanently deleted.']" :confirmWithText="false" :confirmWithPassword="false" />
        @endcan
    </div>
    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($privateKeys as $key)
            @can('view', $key)
                {{-- Admin/Owner: Clickable link --}}
                <a class="coolbox group"
                    href="{{ route('security.private-key.show', ['private_key_uuid' => data_get($key, 'uuid')]) }}" {{ wireNavigate() }}>
                    <div class="flex flex-col justify-center mx-6">
                        <div class="box-title">
                            {{ data_get($key, 'name') }}
                        </div>
                        <div class="box-description">
                            {{ $key->description }}
                            @if (!$key->isInUse())
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium bg-warning-400 text-black">Unused</span>
                            @endif
                        </div>
                    </div>
                </a>
            @else
                {{-- Member: Visible but not clickable --}}
                <div class="coolbox opacity-60 !cursor-not-allowed hover:bg-transparent dark:hover:bg-transparent" title="You don't have permission to view this private key">
                    <div class="flex flex-col justify-center mx-6">
                        <div class="box-title">
                            {{ data_get($key, 'name') }}
                            <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium bg-gray-400 dark:bg-gray-600 text-white">View Only</span>
                        </div>
                        <div class="box-description">
                            {{ $key->description }}
                            @if (!$key->isInUse())
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium bg-warning-400 text-black">Unused</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endcan
        @empty
            <div>No private keys found.</div>
        @endforelse
    </div>
</div>
