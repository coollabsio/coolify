<div class="env-table-item"
    x-show="typeof envFilter === 'undefined' || envFilter === 'all' || envFilter === '{{ $isPreview ? 'preview' : 'production' }}'">
    <div class="data-table-row env-table-grid {{ $showEnvironmentType ? '' : 'env-table-grid-no-type' }}">
        <div class="flex min-w-0 items-center gap-2">
            <button type="button" data-env-name-trigger
                class="env-key-label min-w-0 truncate text-left font-mono text-[13px] text-black dark:text-fg"
                title="{{ $key }}"
                @click="$el.closest('.data-table-row').querySelector('[data-env-settings-trigger]').click()">
                {{ $key }}
            </button>
            @if (filled($comment))
                <x-helper :helper="e($comment)" />
            @endif
            @if ($serviceName)
                <span class="table-badge shrink-0">{{ $serviceName }}</span>
            @endif
        </div>
        <span class="env-managed-desktop data-table-cell-check">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                stroke="currentColor" class="size-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
            </svg>
        </span>
        @if ($showEnvironmentType)
            <div class="env-type-desktop text-[13px] text-neutral-500 dark:text-fg-dim">{{ $isPreview ? 'Preview' : 'Production' }}</div>
        @endif
        <span class="data-table-cell-dash">-</span>
        <span class="data-table-cell-dash">-</span>
        <span class="data-table-cell-dash">-</span>
        <span class="data-table-cell-dash">-</span>
        <div class="justify-self-end">
            <x-modal-input title="Environment variable details" :closeOutside="false">
                <x-slot:content>
                    <button type="button" data-env-settings-trigger class="icon-button shrink-0"
                        title="View environment variable" aria-label="View environment variable">
                        <x-reicon name="settings" class="size-3.5" />
                    </button>
                </x-slot:content>
                <div class="flex w-full flex-col gap-4">
                    <x-forms.input label="Name" :value="$key" readonly />
                    <x-forms.input label="Value" :value="$value ?? ''" readonly />
                    @if (filled($comment))
                        <x-forms.input label="Comment" :value="$comment" readonly />
                    @endif
                    <x-callout type="info" title="Managed by Docker Compose">
                        Update this value in the Compose file.
                    </x-callout>
                </div>
            </x-modal-input>
        </div>
    </div>
</div>
