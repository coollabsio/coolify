<div class="env-table-item"
    x-show="typeof envFilter === 'undefined' || envFilter === 'all' || envFilter === '{{ $isPreview ? 'preview' : 'production' }}'">
    <div class="data-table-row env-table-grid">
        <div class="flex min-w-0 items-center gap-2">
            <span class="truncate font-mono text-[13px] text-black dark:text-fg" title="{{ $key }}">{{ $key }}</span>
            <span class="table-badge shrink-0">Hardcoded</span>
            @if ($serviceName)
                <span class="table-badge shrink-0">{{ $serviceName }}</span>
            @endif
        </div>
        <div class="text-[13px] text-neutral-500 dark:text-fg-dim">
            {{ $isPreview ? 'Preview' : 'Production' }}
        </div>
        <div class="min-w-0 truncate text-[13px] text-neutral-500 dark:text-fg-dim">
            {{ $comment ?: ($value !== null && $value !== '' ? '—' : 'Inherited from host') }}
        </div>
        <span class="data-table-cell-dash">—</span>
        <span class="data-table-cell-dash">—</span>
        <span class="data-table-cell-dash">—</span>
        <span class="data-table-cell-dash">—</span>
        <div></div>
    </div>
</div>
