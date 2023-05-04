@props([
    'show' => null,
    'message' => 'Are you sure you want to delete this?',
    'action' => 'delete',
])
<div x-cloak x-show="{{ $show }}" x-transition.opacity class="fixed inset-0 bg-slate-900/75"></div>
<div x-cloak x-show="{{ $show }}" x-transition class="fixed inset-0 z-50 flex items-center justify-center">
    <div @click.away="{{ $show }} = false" class="w-screen h-20 max-w-xl mx-auto bg-black rounded-lg">
        <div class="flex flex-col items-center justify-center h-full">
            <div class="pb-5 text-white">{{ $message }}</div>
            <div>
                <x-inputs.button isWarning wire:click='{{ $action }}'>
                    Yes
                </x-inputs.button>
                <x-inputs.button x-on:click="{{ $show }} = false">No</x-inputs.button>
            </div>
        </div>
    </div>
</div>
