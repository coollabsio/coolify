<div x-data
    x-init="window.toast = function(message, options = {}) {
        try {
            window.dispatchEvent(new CustomEvent('toast-show', {
                detail: {
                    type: options.type ?? 'default',
                    message,
                    description: options.description ?? '',
                    position: options.position ?? 'top-center',
                    html: options.html ?? '',
                },
            }));
        } catch (error) {
            console.error('Error showing toast:', error);
        }
    }">
    <template x-teleport="body">
        <ul x-data="{
            toasts: [],
            position: 'top-center',
            addToast(event) {
                this.position = event.detail.position || 'top-center';

                const toast = {
                    id: `toast-${Math.random().toString(16).slice(2)}`,
                    visible: false,
                    message: event.detail.message,
                    description: event.detail.description,
                    type: event.detail.type,
                    html: event.detail.html ? window.sanitizeHTML(event.detail.html) : '',
                    timeout: null,
                };

                this.toasts.unshift(toast);
                if (this.toasts.length > 4) {
                    const removed = this.toasts.pop();
                    clearTimeout(removed?.timeout);
                }

                this.$nextTick(() => {
                    const activeToast = this.toasts.find(item => item.id === toast.id);
                    if (!activeToast) return;

                    activeToast.visible = true;
                    this.scheduleToast(activeToast, 4000);
                });
            },
            scheduleToast(toast, delay = 2000) {
                clearTimeout(toast.timeout);
                toast.timeout = setTimeout(() => this.removeToast(toast.id), delay);
            },
            pauseToast(toast) {
                clearTimeout(toast.timeout);
            },
            resumeToast(toast) {
                this.scheduleToast(toast);
            },
            removeToast(id) {
                const toast = this.toasts.find(item => item.id === id);
                if (!toast) return;

                toast.visible = false;
                clearTimeout(toast.timeout);
                setTimeout(() => {
                    this.toasts = this.toasts.filter(item => item.id !== id);
                }, 150);
            },
        }" @toast-show.window="addToast($event)"
            class="fixed z-9999 flex w-[calc(100%-2rem)] gap-2.5 sm:max-w-[26rem]"
            :class="{
                'right-4 top-4 flex-col': position === 'top-right',
                'left-4 top-4 flex-col': position === 'top-left',
                'left-1/2 top-4 -translate-x-1/2 flex-col': position === 'top-center',
                'right-4 bottom-4 flex-col-reverse': position === 'bottom-right',
                'left-4 bottom-4 flex-col-reverse': position === 'bottom-left',
                'left-1/2 bottom-4 -translate-x-1/2 flex-col-reverse': position === 'bottom-center',
            }"
            x-cloak>
            <template x-for="toast in toasts" :key="toast.id">
                <li x-show="toast.visible"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="translate-y-2 opacity-0"
                    x-transition:enter-end="translate-y-0 opacity-100"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="translate-y-0 opacity-100"
                    x-transition:leave-end="-translate-y-1 opacity-0"
                    @mouseenter="pauseToast(toast)" @mouseleave="resumeToast(toast)"
                    class="relative flex w-full items-start rounded-lg group"
                    style="background: var(--coollabs-elevated); box-shadow: 0 0 0 1px var(--coollabs-line), var(--shadow-modal);"
                    :class="{ 'p-3.5 pr-20': !toast.html, 'p-0': toast.html }">
                    <template x-if="!toast.html">
                        <div class="flex min-w-0 items-start gap-3">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg"
                                :class="{
                                    'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400': toast.type === 'success',
                                    'bg-coollabs/10 text-coollabs dark:bg-warning/10 dark:text-warning': toast.type === 'info',
                                    'bg-amber-100 text-amber-700 dark:bg-warning/10 dark:text-warning': toast.type === 'warning',
                                    'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-400': toast.type === 'danger',
                                    'bg-neutral-100 text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim': toast.type === 'default',
                                }">
                                <x-reicon name="check-circle" x-show="toast.type === 'success'"
                                    class="size-4" />
                                <x-reicon name="info-circle"
                                    x-show="toast.type === 'info' || toast.type === 'default'"
                                    class="size-4" />
                                <x-reicon name="alert-triangle" x-show="toast.type === 'warning'"
                                    class="size-4" />
                                <x-reicon name="alert-circle" x-show="toast.type === 'danger'"
                                    class="size-4" />
                            </div>

                            <div class="min-w-0 flex-1 pt-0.5">
                                <p class="text-sm font-semibold leading-5 text-neutral-950 dark:text-fg"
                                    x-text="toast.message"></p>
                                <div x-show="toast.description"
                                    class="mt-0.5 w-full whitespace-pre-wrap break-words text-xs leading-5 text-neutral-600 dark:text-fg-dim"
                                    x-html="window.sanitizeHTML(toast.description)"></div>
                            </div>
                        </div>
                    </template>

                    <template x-if="toast.html">
                        <div class="w-full" x-html="window.sanitizeHTML(toast.html)"></div>
                    </template>

                    <button type="button" x-show="toast.description && !toast.html"
                        @click="navigator.clipboard.writeText(toast.description)" title="Copy details"
                        class="absolute right-10 top-2.5 flex size-7 items-center justify-center rounded-md text-neutral-400 opacity-0 transition-colors hover:bg-black/5 hover:text-neutral-700 group-hover:opacity-100 dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                        <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" fill="none"
                            viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M8.25 7.5V6a2.25 2.25 0 012.25-2.25h7.5A2.25 2.25 0 0120.25 6v7.5A2.25 2.25 0 0118 15.75h-1.5m-8.25-8.25H6A2.25 2.25 0 003.75 9.75v7.5A2.25 2.25 0 006 19.5h7.5a2.25 2.25 0 002.25-2.25V15m-7.5-7.5h5.25A2.25 2.25 0 0115.75 9.75V15" />
                        </svg>
                    </button>

                    <button type="button" @click="removeToast(toast.id)" aria-label="Dismiss"
                        class="absolute right-2.5 top-2.5 flex size-7 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-black/5 hover:text-neutral-700 dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                        <x-reicon name="x" class="size-3.5" />
                    </button>
                </li>
            </template>
        </ul>
    </template>
</div>
