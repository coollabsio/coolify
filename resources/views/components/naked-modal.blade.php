@props([
    'show' => null,
    'message' => 'Are you sure you want to delete this?',
    'action' => 'delete',
])
<div x-cloak x-show="{{ $show }}" x-transition class="modal modal-open">
    <div class="relative modal-box">
        <div class="pb-8 text-base font-bold text-white">{{ $message }}</div>
        <div class="flex justify-end gap-4 text-xs">
            <x-forms.button wire:click='{{ $action }}' x-on:click="{{ $show }} = false">
                Yes
            </x-forms.button>
            <x-forms.button x-on:click="{{ $show }} = false">No</x-forms.button>
        </div>
    </div>
</div>
