<div>
    <x-security.navbar />
    
    <div class="flex gap-2">
        <h2 class="pb-4">Private Keys</h2>
        <x-modal-input buttonTitle="+ Add" title="New Private Key">
            <livewire:security.private-key.create />
        </x-modal-input>
        <x-modal-confirmation
            title="Confirm unused SSH Key Deletion?"
            buttonTitle="Delete unused SSH Keys"
            isErrorButton
            submitAction="cleanupUnusedKeys"
            :actions="['All unused SSH keys (marked with unused) are permanently deleted.']"
            :confirmWithText="false"
            :confirmWithPassword="false"
        />
    </div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($privateKeys as $key)
            <a class="box group"
                wire:navigate
                href="{{ route('security.private-key.show', ['private_key_uuid' => data_get($key, 'uuid')]) }}">
                <div class="flex flex-col mx-6">
                    <div class="box-title">
                        {{ data_get($key, 'name') }}
                    </div>
                    <div class="box-description">
                        {{ $key->description }}
                         @if (!$key->isInUse())
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-400 text-black">Unused</span>
                        @endif
                    </div>
                   
                </div>
            </a>
        @empty
            <div>No private keys found.</div>
        @endforelse
    </div>
</div>