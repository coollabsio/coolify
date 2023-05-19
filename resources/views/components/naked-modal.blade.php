@props([
    'show' => null,
    'message' => 'Are you sure you want to delete this?',
    'action' => 'delete',
])
<div x-cloak x-show="{{ $show }}" x-transition class="modal modal-open">
    <div class="relative modal-box">
        <div class="pb-8 text-base font-bold text-white">{{ $message }}</div>
        <div class="flex justify-end gap-4 text-xs">
            <x-inputs.button isWarning wire:click='{{ $action }}' x-on:click="{{ $show }} = false">
                Yes
            </x-inputs.button>
            <x-inputs.button x-on:click="{{ $show }} = false">No</x-inputs.button>
        </div>
    </div>
</div>
