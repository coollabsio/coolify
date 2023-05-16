@props([
    'show' => null,
    'message' => 'Are you sure you want to delete this?',
    'action' => 'delete',
])
<div x-cloak x-show="{{ $show }}" x-transition.opacity class="fixed inset-0 bg-coolgray-100/75"></div>
<div x-cloak x-show="{{ $show }}" x-transition class="fixed inset-0 z-50 top-20">
    <div @click.away="{{ $show }} = false" class="w-screen max-w-xl mx-auto rounded-lg shadow-xl bg-coolgray-200">
        <div class="flex flex-col items-center justify-center h-full p-4">
            <div class="pb-5 text-xs text-white">{{ $message }}</div>
            <div class="text-xs">
                <x-inputs.button isWarning wire:click='{{ $action }}' x-on:click="{{ $show }} = false">
                    Yes
                </x-inputs.button>
                <x-inputs.button x-on:click="{{ $show }} = false">No</x-inputs.button>
            </div>
        </div>
    </div>
</div>
