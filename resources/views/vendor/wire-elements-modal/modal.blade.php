<div>
    @isset($jsPath)
        <script>
            {!! file_get_contents($jsPath) !!}
        </script>
    @endisset
    @isset($cssPath)
        <style>
            {!! file_get_contents($cssPath) !!}
        </style>
    @endisset

    <div x-data="LivewireUIModal()" x-on:close.stop="setShowPropertyTo(false)"
        x-on:keydown.escape.window="closeModalOnEscape()" x-show="show" class="fixed inset-0 overflow-y-auto z-[60]"
        style="display: none;">
        <div class="flex items-end justify-center min-h-screen px-4 pt-4 pb-10 text-center sm:block sm:p-0">
            <div x-show="show" x-on:click="closeModalOnClickAway()" x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0" class="fixed inset-0 transition-all transform">
                <div class="absolute inset-0 bg-black opacity-70"></div>
            </div>

            {{-- <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span> --}}

            <div x-show="show && showActiveComponent" x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                x-bind:class="modalWidth"
                class="w-full overflow-hidden text-left align-bottom transition-all transform sm:align-middle sm:w-full"
                id="modal-container" x-trap.noscroll.inert="show && showActiveComponent" aria-modal="true">
                @forelse($components as $id => $component)
                    <div class="sm:mx-20 sm:my-8" x-show.immediate="activeComponent == '{{ $id }}'"
                        x-ref="{{ $id }}" wire:key="{{ $id }}">
                        @livewire($component['name'], $component['arguments'], key($id))
                    </div>
                @empty
                @endforelse
            </div>
        </div>
    </div>
</div>
