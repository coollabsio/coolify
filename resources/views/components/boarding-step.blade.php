<div class="w-full max-w-4xl">
    <div class=" rounded-lg shadow-sm border border-neutral-200 dark:border-coolgray-300 overflow-hidden">
        <div class="p-8 lg:p-12">
            <h1 class="text-3xl font-bold lg:text-4xl mb-4">{{ $title }}</h1>
            @isset($question)
                <div class="text-base lg:text-lg dark:text-neutral-400 mb-8">
                    {{ $question }}
                </div>
            @endisset

            @if ($actions)
                <div class="flex flex-col gap-4">
                    {{ $actions }}
                </div>
            @endif
        </div>

        @isset($explanation)
            <div class=" border-t border-neutral-200 dark:border-coolgray-300 p-8 lg:p-12 ">
                <h3 class="text-sm font-bold uppercase tracking-wide mb-4 dark:text-neutral-400">
                    Technical Details
                </h3>
                <div class="space-y-3 text-sm dark:text-neutral-400">
                    {{ $explanation }}
                </div>
            </div>
        @endisset
    </div>
</div>
