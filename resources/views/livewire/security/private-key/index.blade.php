<div>
    <x-security.navbar />
    <div class="flex gap-2">
        <h2 class="pb-4">{{ __('private_keys.heading') }}</h2>
        @can('create', App\Models\PrivateKey::class)
            <x-modal-input buttonTitle="{{ __('button.add') }}" title="{{ __('modal.new_private_key') }}">
                <livewire:security.private-key.create />
            </x-modal-input>
        @endcan
        @can('create', App\Models\PrivateKey::class)
            <x-modal-confirmation title="{{ __('private_keys.confirm_delete_unused') }}" buttonTitle="{{ __('private_keys.delete_unused') }}" isErrorButton
                submitAction="cleanupUnusedKeys" :actions="[__('private_keys.delete_unused_warning')]" :confirmWithText="false" :confirmWithPassword="false" />
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
                                    class="inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium bg-warning-400 text-black">{{ __('private_keys.unused') }}</span>
                            @endif
                        </div>
                    </div>
                </a>
            @else
                {{-- Member: Visible but not clickable --}}
                <div class="coolbox opacity-60 !cursor-not-allowed hover:bg-transparent dark:hover:bg-transparent" title="{{ __('private_keys.no_permission') }}">
                    <div class="flex flex-col justify-center mx-6">
                        <div class="box-title">
                            {{ data_get($key, 'name') }}
                            <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium bg-gray-400 dark:bg-gray-600 text-white">{{ __('private_keys.view_only') }}</span>
                        </div>
                        <div class="box-description">
                            {{ $key->description }}
                            @if (!$key->isInUse())
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium bg-warning-400 text-black">{{ __('private_keys.unused') }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endcan
        @empty
            <div>{{ __('private_keys.no_keys') }}</div>
        @endforelse
    </div>
</div>
