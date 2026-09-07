<div>
    <x-slot:title>
        Integration Tokens | Coolify
    </x-slot>

    <x-security.settings-layout>
        <div class="application-settings-form">
            <x-application.settings-section title="Integration tokens"
                description="Credentials used by third-party integrations such as DNS providers and secret managers." flush>
                <x-slot:actions>
                    @can('create', App\Models\IntegrationToken::class)
                        <x-modal-input title="New Integration Token">
                            <x-slot:content>
                                <button type="button" class="button button-highlighted">
                                    <x-reicon name="plus" class="size-3.5" />
                                    New token
                                </button>
                            </x-slot:content>
                            <livewire:security.integration-token-form :modal_mode="true"
                                wire:key="new-integration-token" />
                        </x-modal-input>
                    @endcan
                </x-slot:actions>

                @if ($tokens->isEmpty())
                    <x-empty title="No integration tokens"
                        description="Add a provider token to connect a third-party integration."
                        icon-name="keys" size="sm" />
                @else
                    <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                        @foreach ($tokens as $savedToken)
                            <div wire:key="integration-token-{{ $savedToken->id }}"
                                x-data="{
                                    visible: true,
                                    tokenName: @js($savedToken->name),
                                    tokenCapabilities: @js($savedToken->capabilities),
                                }"
                                x-show="visible"
                                x-on:integration-token-updated.window="
                                    if ($event.detail.uuid === @js($savedToken->uuid)) {
                                        tokenName = $event.detail.name;
                                        tokenCapabilities = $event.detail.capabilities;
                                    }
                                "
                                x-on:integration-token-deleted.window="
                                    if ($event.detail.uuid === @js($savedToken->uuid)) visible = false
                                ">
                            <x-modal-input title="Edit Integration Token" isFullWidth :wireIgnore="false"
                                :contentClicks="false"
                                class="border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                                <x-slot:content>
                                    <div class="grid min-h-14 w-full grid-cols-[minmax(0,1fr)_8rem_minmax(0,1fr)_2rem] items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-neutral-50 dark:hover:bg-white/[0.025]">
                                        <div class="min-w-0">
                                            <h3 class="truncate text-[13px]! font-semibold! text-black dark:text-fg">
                                                <span x-text="tokenName"></span>
                                            </h3>
                                        </div>
                                        <div class="text-center text-[12px] text-neutral-500 dark:text-fg-dim">
                                            {{ $savedToken->providerName() }}
                                        </div>
                                        <div class="flex flex-wrap gap-1">
                                            <template x-for="capability in tokenCapabilities" :key="capability">
                                                <span x-text="capability"
                                                    class="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium uppercase text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim"></span>
                                            </template>
                                        </div>
                                        <button type="button" class="icon-button" title="Edit integration token"
                                            :aria-label="`Edit ${tokenName}`" @click="modalOpen=true">
                                            <x-reicon name="settings" class="size-3.5" />
                                        </button>
                                    </div>
                                </x-slot:content>
                                <livewire:security.integration-token-editor
                                    :integration_token_uuid="$savedToken->uuid"
                                    :key="'integration-token-editor-'.$savedToken->uuid" />
                            </x-modal-input>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </x-security.settings-layout>
</div>
