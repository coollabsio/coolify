<div role="status" id="toaster" x-data="toasterHub(@js($toasts), @js($config))" @class([
    'fixed z-50 p-4 w-full flex flex-col pointer-events-none sm:p-6',
    'bottom-0' => $alignment->is('bottom'),
    'top-1/2 -translate-y-1/2' => $alignment->is('middle'),
    'top-0' => $alignment->is('top'),
    'items-start' => $position->is('left'),
    'items-center' => $position->is('center'),
    'items-end' => $position->is('right'),
])>
    <template x-for="toast in toasts" :key="toast.id">
        <div x-show="toast.isVisible" x-init="$nextTick(() => toast.show($el))" @if ($alignment->is('bottom'))
            x-transition:enter-start="translate-y-12 opacity-0"
            x-transition:enter-end="translate-y-0 opacity-100"
        @elseif($alignment->is('top'))
            x-transition:enter-start="-translate-y-12 opacity-0"
            x-transition:enter-end="translate-y-0 opacity-100"
        @else
            x-transition:enter-start="opacity-0 scale-90"
            x-transition:enter-end="opacity-100 scale-100"
            @endif
            x-transition:leave-end="opacity-0 scale-90"
            class="relative flex duration-300 transform transition ease-in-out max-w-md w-full pointer-events-auto {{ $position->is('center') ? 'text-center' : 'text-left' }}"
            :class="toast.select({ error: 'text-white', info: 'text-white', success: 'text-white', warning: 'text-white' })"
            >
            <i class=" flex items-center gap-2 select-none not-italic pr-6 pl-4 py-3 rounded shadow-lg text-sm w-full {{ $alignment->is('bottom') ? 'mt-3' : 'mb-3' }}"
                :class="toast.select({
                    error: 'bg-coolgray-300',
                    info: 'bg-coolgray-300',
                    success: 'bg-coolgray-300',
                    warning: 'bg-coolgray-300'
                })">
                <template x-if="toast.type === 'success'">
                    <div class="rounded text-success">
                        <svg aria-hidden="true" class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20"
                            xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd"
                                d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                clip-rule="evenodd"></path>
                        </svg>
                    </div>
                </template>
                <template x-if="toast.type === 'error'">
                    <div class="rounded text-error">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
                            <path d="M12 9v4" />
                            <path d="M12 16v.01" />
                        </svg>
                    </div>
                </template>
                <template x-if="toast.type === 'info'">
                    <div class="rounded text-info">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" />
                            <path d="M12 9h.01" />
                            <path d="M11 12h1v4h1" />
                        </svg>
                    </div>
                </template>
                <template x-if="toast.type === 'warning'">
                    <div class="rounded text-warning">
                        <svg aria-hidden="true" class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20"
                            xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd"
                                d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                                clip-rule="evenodd"></path>
                        </svg>
                    </div>
                </template>
                <span x-text="toast.message" />
            </i>

            @if ($closeable)
                <button @click="toast.dispose()" aria-label="@lang('close')"
                    class="absolute right-0 p-4 focus:outline-none hover:bg-transparent/10 rounded {{ $alignment->is('bottom') ? 'top-3' : 'top-0' }}">
                    <svg aria-hidden="true" class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"
                        xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd"
                            d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                            clip-rule="evenodd"></path>
                    </svg>
                </button>
            @endif
        </div>
    </template>
</div>
