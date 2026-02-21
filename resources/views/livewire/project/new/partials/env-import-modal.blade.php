@if (count($envExampleVars) > 0)
    <template x-teleport="body">
        <div x-show="envModalOpen" @keydown.window.escape="envModalOpen = false"
            class="fixed top-0 left-0 z-99 flex items-center justify-center w-screen h-screen p-4">
            <div x-show="envModalOpen" x-transition:enter="ease-out duration-100"
                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0" @click="envModalOpen = false"
                class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
            <div x-show="envModalOpen" x-trap.inert.noscroll="envModalOpen"
                x-transition:enter="ease-out duration-100"
                x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                class="relative w-full lg:w-auto lg:min-w-xl lg:max-w-2xl border rounded-sm drop-shadow-sm bg-white border-neutral-200 dark:bg-base dark:border-coolgray-300 flex flex-col">
                <div class="flex items-center justify-between py-6 px-6 shrink-0">
                    <h3 class="text-lg font-bold">Import from {{ $selectedEnvFile }}</h3>
                    <button @click="envModalOpen = false"
                        class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300 outline-0">
                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                @if (count($detectedEnvFiles) > 1)
                    <div class="px-6 pb-3">
                        <x-forms.select wire:model.live="selectedEnvFile" label="Env File"
                            helper="Multiple env files were detected. Select which one to import.">
                            @foreach ($detectedEnvFiles as $envFile)
                                <option value="{{ $envFile }}">{{ $envFile }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                @endif
                <div class="px-6 pb-3 text-sm dark:text-neutral-400">
                    Review and edit the values below. Imported variables will be added to your application when you continue.
                </div>
                <div class="flex flex-col gap-3 px-6 pb-2 max-h-80 overflow-y-auto scrollbar">
                    @foreach ($envExampleVars as $key => $value)
                        <div class="flex gap-3 items-center">
                            <label class="w-1/3 text-sm font-mono truncate dark:text-neutral-400" title="{{ $key }}">{{ $key }}</label>
                            <input type="text" class="w-2/3 input"
                                wire:model="envExampleVars.{{ $key }}" />
                        </div>
                    @endforeach
                </div>
                <div class="flex items-center justify-between gap-4 px-6 py-5">
                    <x-forms.button @click="envModalOpen = false">
                        Close
                    </x-forms.button>
                    <div class="flex items-center gap-2">
                        @if ($envImported)
                            <x-forms.button wire:click="clearEnvVars" @click="envModalOpen = false">
                                Remove Import
                            </x-forms.button>
                        @endif
                        <x-forms.button isHighlighted wire:click="confirmEnvImport" @click="envModalOpen = false">
                            {{ $envImported ? 'Update' : 'Import' }} {{ count($envExampleVars) }} Variable{{ count($envExampleVars) > 1 ? 's' : '' }}
                        </x-forms.button>
                    </div>
                </div>
            </div>
        </div>
    </template>
@endif
