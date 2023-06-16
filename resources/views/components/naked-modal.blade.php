@props([
    'show' => null,
    'message' => 'Are you sure you want to delete this?',
    'action' => 'delete',
])
<div x-cloak x-show="{{ $show }}" x-transition class="relative z-10" aria-labelledby="modal-title" role="dialog"
    aria-modal="true">
    <div class="fixed inset-0 transition-opacity bg-coolgray-100/75"></div>
    <div class="fixed inset-0 z-10 overflow-y-auto">
        <div class="flex items-end justify-center min-h-full p-4 text-center sm:items-center sm:p-0">
            <div
                class="relative px-4 pt-5 pb-4 overflow-hidden text-left text-white transition-all transform rounded-lg shadow-xl bg-coolgray-200 sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                <div class="sm:flex sm:items-start">
                    <div
                        class="flex items-center justify-center flex-shrink-0 w-12 h-12 mx-auto rounded-full sm:mx-0 sm:h-10 sm:w-10">
                        <svg class="w-8 h-8 text-error" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <div class="mt-4 text-center sm:ml-4 sm:mt-1 sm:text-left">
                        <h3 class="text-base font-semibold leading-6 text-white" id="modal-title">Delete Resource
                        </h3>
                        <div class="mt-2">
                            <p class=" text-neutral-200">{{ $message }}</p>
                        </div>
                    </div>
                </div>
                <div class="gap-4 mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                    <x-forms.button class="w-24" wire:click='{{ $action }}'
                        x-on:click="{{ $show }} = false" isWarning type="button">Delete</x-forms.button>
                    <x-forms.button class="w-24 bg-coolgray-200 hover:bg-coolgray-300"
                        x-on:click="{{ $show }} = false" type="button">Cancel
                    </x-forms.button>
                </div>
            </div>
        </div>
    </div>
</div>
