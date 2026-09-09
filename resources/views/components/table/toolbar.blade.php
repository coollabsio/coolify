<div {{ $attributes->class(['table-toolbar flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center']) }}>
    @isset($search)
        <div class="min-w-0 w-full flex-1 sm:max-w-md">{{ $search }}</div>
    @endisset
    <div class="flex flex-wrap items-center gap-2 sm:ml-auto">{{ $slot }}</div>
</div>
