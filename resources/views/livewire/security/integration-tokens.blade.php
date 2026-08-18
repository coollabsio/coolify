<div>
    <x-slot:title>
        Integration Tokens | Coolify
    </x-slot>

    <x-security.settings-layout>
        <div class="application-settings-form">
            <x-application.settings-section title="Integration tokens"
                description="Credentials used by third-party integrations such as DNS providers." flush>
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
                                class="grid min-h-14 grid-cols-[minmax(0,1fr)_8rem_minmax(0,1fr)_2rem] items-center gap-3 px-4 py-2.5">
                                <div class="min-w-0">
                                    <h3 class="truncate text-[13px]! font-semibold! text-black dark:text-fg">
                                        {{ $savedToken->name }}
                                    </h3>
                                </div>
                                <div class="text-center text-[12px] text-neutral-500 dark:text-fg-dim">
                                    {{ ucfirst($savedToken->provider) }}
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($savedToken->capabilities ?? [] as $capability)
                                        <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium uppercase text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                            {{ $capability }}
                                        </span>
                                    @endforeach
                                </div>
                                <x-modal-confirmation title="Delete integration token?" isErrorButton
                                    submitAction="deleteToken({{ $savedToken->id }})"
                                    confirmationText="{{ $savedToken->name }}"
                                    confirmationLabel="Enter the token name to confirm"
                                    shortConfirmationLabel="Token name" :confirmWithPassword="false"
                                    step2ButtonText="Delete token">
                                    <x-slot:trigger>
                                        <button type="button" class="icon-button" title="Delete token">
                                            <x-reicon name="trash" class="size-3.5" />
                                        </button>
                                    </x-slot:trigger>
                                </x-modal-confirmation>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </x-security.settings-layout>
</div>
