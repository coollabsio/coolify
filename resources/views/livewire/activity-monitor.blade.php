@php use App\Actions\CoolifyTask\RunRemoteProcess; @endphp
<div @class([
    'h-full flex flex-col overflow-hidden' => $fullHeight,
    'h-full overflow-hidden' => !$fullHeight,
])>
    @if ($activity)
        @if (isset($header))
            <div class="flex gap-2 pb-2 flex-shrink-0">
                <h3>{{ $header }}</h3>
                @if ($isPollingActive)
                    <x-loading />
                @endif
            </div>
        @endif
        <div x-data="{
            autoScrollEnabled: true,
            observer: null,
            scrollHandler: null,
            scrollToBottom() {
                if (this.autoScrollEnabled) {
                    this.$el.scrollTop = this.$el.scrollHeight;
                }
            },
            isAtBottom() {
                const threshold = 5; // 5px tolerance
                return this.$el.scrollTop + this.$el.clientHeight >= this.$el.scrollHeight - threshold;
            },
            handleScroll() {
                // Check if user scrolled to bottom
                if (this.isAtBottom()) {
                    this.autoScrollEnabled = true;
                } else {
                    this.autoScrollEnabled = false;
                }
            },
            cleanup() {
                if (this.observer) {
                    this.observer.disconnect();
                    this.observer = null;
                }
                if (this.scrollHandler) {
                    this.$el.removeEventListener('scroll', this.scrollHandler);
                    this.scrollHandler = null;
                }
            }
        }" x-init="// Initial scroll
        $nextTick(() => scrollToBottom());

        // Add scroll event listener
        this.scrollHandler = () => handleScroll();
        $el.addEventListener('scroll', this.scrollHandler);

        // Set up mutation observer to watch for content changes
        this.observer = new MutationObserver(() => {
            $nextTick(() => scrollToBottom());
        });
        this.observer.observe($el, {
            childList: true,
            subtree: true,
            characterData: true
        });" x-destroy="cleanup()"
            @class([
                'flex flex-col w-full px-4 py-2 overflow-y-auto bg-white border border-solid rounded-sm dark:text-white dark:bg-coolgray-100 scrollbar border-neutral-300 dark:border-coolgray-300',
                'flex-1 min-h-0' => $fullHeight,
                'max-h-96' => !$fullHeight,
            ])>
            <pre class="font-mono whitespace-pre-wrap" @if ($isPollingActive) wire:poll.1000ms="polling" @endif>{{ RunRemoteProcess::decodeOutput($activity) }}</pre>
        </div>
    @else
        @if ($showWaiting)
            <div class="flex justify-start">
                <x-loading text="Waiting for the process to start..." />
            </div>
        @endif
    @endif
</div>
