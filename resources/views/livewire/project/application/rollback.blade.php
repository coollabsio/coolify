<div x-init="$wire.loadImages">
    <h2>Revert</h2>
    <div>
        <x-inputs.button isBold wire:click='loadImages'>Refresh Images</x-inputs.button>
    </div>
    <div wire:loading wire:target='loadImages'>
        <x-loading />
    </div>
    <div wire:loading.remove wire:target='loadImages'>
        <div class="flex flex-wrap">
            @forelse ($images as $image)
                <div class="w-1/2 p-2">
                    <div class="rounded-lg shadow-lg bg-coolgray-200">
                        <div class="p-2">
                            <div class="text-sm">
                                @if (data_get($image, 'is_current'))
                                    <span class="font-bold text-coollabs">LIVE</span>
                                    |
                                @endif
                                {{ data_get($image, 'tag') }}
                            </div>

                            <div class="text-xs">{{ data_get($image, 'created_at') }}</div>
                        </div>
                        <div class="flex justify-end p-2">
                            <x-inputs.button wire:click="revertImage('{{ data_get($image, 'tag') }}')">
                                Revert
                            </x-inputs.button>
                        </div>
                    </div>
                </div>
            @empty
                <div>No images found</div>
            @endforelse
        </div>
    </div>
</div>
