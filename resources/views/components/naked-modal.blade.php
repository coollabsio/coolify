@props([
    'show' => null,
    'message' => 'Are you sure you want to delete this?',
    'action' => 'delete',
])
<div x-cloak x-show="{{ $show }}" x-transition class="modal modal-open">
    <div class="relative text-center rounded modal-box bg-coolgray-100">
        <div class="pb-8 text-base text-white">{{ $message }}</div>
        <div class="flex justify-center gap-4 text-xs">
            <x-forms.button class="w-32 hover:bg-coolgray-400 bg-coolgray-200 h-7" isWarning
                wire:click='{{ $action }}' x-on:click="{{ $show }} = false">
                Yes
            </x-forms.button>
            <x-forms.button class="w-32 hover:bg-coolgray-400 bg-coolgray-200 h-7"
                x-on:click="{{ $show }} = false">No</x-forms.button>
        </div>
    </div>
</div>
