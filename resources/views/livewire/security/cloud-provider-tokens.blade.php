<div class="application-settings-form">
    <x-application.settings-section title="Cloud tokens"
        description="Provider credentials used to provision cloud servers." flush>
        <x-slot:actions>
            @can('create', App\Models\CloudProviderToken::class)
                <x-modal-input title="New Cloud Token">
                    <x-slot:content>
                        <button type="button"
                            class="button button-highlighted">
                            <x-reicon name="plus" class="size-3.5" />
                            New token
                        </button>
                    </x-slot:content>
                    <livewire:security.cloud-provider-token-form :modal_mode="true" wire:key="new-cloud-provider-token" />
                </x-modal-input>
            @endcan
        </x-slot:actions>
        @if ($tokens->isEmpty())
            <x-empty title="No cloud tokens"
                description="Add a provider token to provision new cloud servers." icon-name="keys" size="sm" />
        @else
            <div>
                <div class="grid grid-cols-[minmax(0,1fr)_8rem_1.75rem] items-center gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[13px] font-medium text-neutral-500 sm:grid-cols-[minmax(0,1fr)_8rem_minmax(0,1fr)_1.75rem] dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                    <div class="pl-11">Token</div>
                    <div class="text-center">Provider</div>
                    <div class="hidden sm:block">Description</div>
                    <div class="w-7"></div>
                </div>
                @foreach ($tokens as $savedToken)
                    <x-modal-input title="Edit Cloud Token" isFullWidth :wireIgnore="false" :contentClicks="false"
                        wire:key="cloud-token-{{ $savedToken->id }}"
                        class="border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                        <x-slot:content>
                    <div
                        class="grid min-h-14 w-full grid-cols-[minmax(0,1fr)_8rem_1.75rem] items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-neutral-50 sm:grid-cols-[minmax(0,1fr)_8rem_minmax(0,1fr)_1.75rem] dark:hover:bg-white/[0.025]"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                <x-reicon name="keys" class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                    {{ $savedToken->name }}
                                </h3>
                            </div>
                        </div>
                        <div class="flex justify-center">
                            <span
                                class="inline-flex rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                {{ $savedToken->provider === 'digitalocean' ? 'DigitalOcean' : ucfirst($savedToken->provider) }}
                            </span>
                        </div>
                        <p class="hidden truncate text-[12px] text-neutral-500 sm:block dark:text-fg-dim">{{ $savedToken->description ?: '-' }}</p>
                        <button type="button" class="icon-button" title="Edit cloud token"
                            aria-label="Edit {{ $savedToken->name }}" @click="modalOpen=true">
                            <x-reicon name="settings" class="size-3.5" />
                        </button>
                    </div>
                        </x-slot:content>
                        <livewire:security.cloud-provider-token.show :cloud_token_uuid="$savedToken->uuid"
                            :modalMode="true" :key="'cloud-token-editor-'.$savedToken->uuid" />
                    </x-modal-input>
                @endforeach
            </div>
        @endif
    </x-application.settings-section>

</div>
