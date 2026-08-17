<div class="application-settings-form w-full max-w-3xl">
    <section class="application-settings-section">
        <div class="application-settings-section-header">
            <div>
                <h2>{{ $title }}</h2>
                @isset($question)
                    <p>{{ $question }}</p>
                @endisset
            </div>
        </div>

        <div class="application-settings-section-body">
            @if ($actions)
                <div class="flex flex-col gap-4">
                    {{ $actions }}
                </div>
            @endif
        </div>

        @isset($explanation)
            <div
                class="border-t border-neutral-200 bg-neutral-50 px-4 py-3 dark:border-white/[0.08] dark:bg-white/[0.02]">
                <p class="mb-2 text-[10px] font-semibold uppercase tracking-[0.08em] text-neutral-400 dark:text-fg-faint">
                    Technical details
                </p>
                <div class="space-y-2 text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">
                    {{ $explanation }}
                </div>
            </div>
        @endisset
    </section>
</div>
