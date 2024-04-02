<div class="grid grid-cols-1 gap-4 md:grid-cols-3">
    <div class="box-border col-span-2 lg:min-w-[24rem] lg:min-h-[21rem]">
        <h1 class="text-2xl font-bold lg:text-5xl">{{ $title }}</h1>
        <div class="py-6">
            @isset($question)
                <p class="dark:text-neutral-400">
                    {{ $question }}
                </p>
            @endisset
        </div>
        @if ($actions)
            <div class="flex flex-col flex-wrap gap-4 lg:items-center md:flex-row">
                {{ $actions }}
            </div>
        @endif
    </div>
    @isset($explanation)
        <div class="col-span-1">
            <h3 class="pb-8 font-bold">Explanation</h3>
            <div class="space-y-4">
                {{ $explanation }}
            </div>
        </div>
    @endisset
</div>
