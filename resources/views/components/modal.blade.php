<dialog id="{{ $modalId }}" class="modal">
    @if ($yesOrNo)
        <form method="dialog" class="rounded modal-box" @if (!$noSubmit) wire:submit='submit' @endif>
            <div class="flex items-start">
                <div class="flex items-center justify-center flex-shrink-0 w-10 h-10 mr-4 rounded-full">
                    <svg class="w-8 h-8 text-error" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                        stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                </div>
                <div class="flex flex-col w-full gap-2">
                    @isset($modalTitle)
                        <h3 class="text-lg font-bold">{{ $modalTitle }}</h3>
                    @endisset
                    @isset($modalBody)
                        {{ $modalBody }}
                    @endisset
                    @if ($modalSubmit)
                        {{ $modalSubmit }}
                    @else
                        <div class="flex gap-4 mt-4">
                            <x-forms.button class="w-24 bg-coolgray-200 hover:bg-coolgray-100" type="button"
                                onclick="{{ $modalId }}.close()">Cancel
                            </x-forms.button>
                            <div class="flex-1"></div>
                            <x-forms.button class="w-24" isError type="button"
                                wire:click.prevent='{{ $action }}' onclick="{{ $modalId }}.close()">Continue
                            </x-forms.button>
                        </div>
                    @endif
                </div>
            </div>
        </form>
    @else
        <form method="dialog" class="flex flex-col w-11/12 max-w-5xl gap-2 rounded modal-box"
            @if ($submitWireAction) wire:submit={{ $submitWireAction }} @endif
            @if (!$noSubmit && !$submitWireAction) wire:submit='submit' @endif>
            @isset($modalTitle)
                <h3 class="text-lg font-bold">{{ $modalTitle }}</h3>
            @endisset
            @isset($modalBody)
                {{ $modalBody }}
            @endisset
            @if ($modalSubmit)
                {{ $modalSubmit }}
            @endif

        </form>
    @endif

    <form method="dialog" class="modal-backdrop">
        <button>close</button>
    </form>
</dialog>
