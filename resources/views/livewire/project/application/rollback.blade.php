<div x-init="$wire.loadImages">
    <div class="flex items-center gap-2">
        <h2>Rollback</h2>
        <x-forms.button wire:click='loadImages(true)'>Reload Available Images</x-forms.button>
    </div>
    <div class="pb-4 ">You can easily rollback to a previously built (local) images
        quickly.</div>
    <div wire:target='loadImages'>
        <div class="flex flex-wrap">
            @forelse ($images as $image)
                <div class="w-2/4 p-2">
                    <div class="bg-white border rounded dark:border-black dark:bg-coolgray-100">
                        <div class="p-2">
                            <div class="">
                                @if (data_get($image, 'is_current'))
                                    <span class="font-bold dark:text-warning">LIVE</span>
                                    |
                                @endif
                                SHA: {{ data_get($image, 'tag') }}
                            </div>
                            @php
                                $date = data_get($image, 'created_at');
                                $interval = \Illuminate\Support\Carbon::parse($date);
                            @endphp
                            <div class="text-xs">{{ $interval->diffForHumans() }}</div>
                            <div class="text-xs">{{ $date }}</div>
                        </div>
                        <div class="flex justify-end p-2">
                            @if (data_get($image, 'is_current'))
                                <x-forms.button disabled tooltip="This image is currently running.">
                                    Rollback
                                </x-forms.button>
                            @else
                                <x-forms.button class="bg-coolgray-100"
                                    wire:click="rollbackImage('{{ data_get($image, 'tag') }}')">
                                    Rollback
                                </x-forms.button>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div>No images found locally.</div>
            @endforelse
        </div>
    </div>
</div>
