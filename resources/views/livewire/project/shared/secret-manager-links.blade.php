<div class="mt-8">
    @php
        $secretManagerDescription = 'Reference remote secrets in your environment variables with {{vault.KEY}}. Values are fetched at deployment time and are never stored in the Coolify database. Changing the source does not re-check existing references — missing keys fail the next deployment.';
        $removeSourceWarning = 'Existing {{vault.*}} reference variables will fail the next deployment until they are removed too.';
    @endphp
    <x-application.settings-section title="Secret manager" :description="$secretManagerDescription">

        @if (! $link && $availableTokens->isEmpty())
            <x-empty title="No secret manager tokens"
                description="Add a Doppler, Infisical, or HashiCorp Vault token under Keys & Tokens > Integration Tokens first."
                icon-name="keys" size="sm" />
        @else
            @can('update', $resource)
                <div class="application-settings-form flex w-full flex-col gap-4">
                    <div class="flex items-end gap-2">
                        <x-forms.listbox id="integration_token_uuid" label="Integration token" :live="true"
                            placeholder="Select a secret manager token" :options="$availableTokens
                                ->map(fn ($token) => [
                                    'value' => $token->uuid,
                                    'label' => $token->name.' ('.$token->providerName().')',
                                ])
                                ->all()" />
                        @if ($link)
                            <x-modal-confirmation title="Remove secret manager source?" isErrorButton
                                buttonTitle="Remove" submitAction="removeSource"
                                :actions="[$removeSourceWarning]"
                                :confirmWithText="false" :confirmWithPassword="false"
                                step1ButtonText="Remove source" />
                        @endif
                    </div>

                    @if ($link)
                        @if ($selectedToken?->provider === 'doppler')
                            @if ($selectedToken->dopplerTokenType() === 'service_account')
                                <div class="grid gap-4 lg:grid-cols-2">
                                    <x-forms.input required id="settings.project" label="Project (required)"
                                        wire:blur="saveSettings" />
                                    <x-forms.input required id="settings.config" label="Config (required)" placeholder="prd"
                                        wire:blur="saveSettings" />
                                </div>
                            @else
                                <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                    Project and config are fixed by this service token.
                                </p>
                            @endif
                        @elseif ($selectedToken?->provider === 'infisical')
                            <div class="grid gap-4 lg:grid-cols-3">
                                <x-forms.input required id="settings.project_id" label="Project ID"
                                    wire:blur="saveSettings" />
                                <x-forms.input required id="settings.environment" label="Environment slug"
                                    placeholder="prod" wire:blur="saveSettings" />
                                <x-forms.input id="settings.secret_path" label="Secret path" placeholder="/"
                                    wire:blur="saveSettings" />
                            </div>
                        @elseif ($selectedToken?->provider === 'vault')
                            <div class="grid gap-4 lg:grid-cols-2">
                                <x-forms.input required id="settings.mount" label="KV v2 mount" placeholder="secret"
                                    wire:blur="saveSettings" />
                                <x-forms.input required id="settings.path" label="Secret path"
                                    placeholder="my-app/production" wire:blur="saveSettings" />
                            </div>
                        @endif

                        <div class="flex flex-col gap-3">
                            <div class="flex items-center gap-2">
                                <x-forms.button wire:click="loadKeys" wire:target="loadKeys">
                                    {{ $keysLoaded ? 'Reload keys' : 'Browse keys' }}
                                </x-forms.button>
                                @if ($keysLoaded)
                                    <x-forms.button wire:click="importAll" wire:target="importAll" isHighlighted>
                                        Import all keys
                                    </x-forms.button>
                                @endif
                            </div>

                            @if ($keysLoaded)
                                @if (count($keys) === 0)
                                    <div class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                        No secrets found at this source.
                                    </div>
                                @else
                                    <x-forms.input label="Search keys" placeholder="Filter key names"
                                        wire:model.live.debounce.300ms="search" />
                                    <div class="divide-y divide-neutral-200 rounded-lg border border-neutral-200 dark:divide-white/[0.07] dark:border-white/[0.08]">
                                        @forelse ($filteredKeys as $key)
                                            <div wire:key="secret-key-{{ $key }}"
                                                class="flex items-center justify-between gap-3 px-3 py-2">
                                                <div class="flex min-w-0 flex-col">
                                                    <span class="font-mono text-[12px] text-black dark:text-fg">{{ $key }}</span>
                                                    <span class="font-mono text-[11px] text-neutral-400 dark:text-fg-dim">{{ '{{vault.'.$key.'}'.'}' }}</span>
                                                </div>
                                                <x-forms.button wire:click="addReference({{ \Illuminate\Support\Js::from($key) }})"
                                                    wire:target="addReference({{ \Illuminate\Support\Js::from($key) }})">
                                                    Add as variable
                                                </x-forms.button>
                                            </div>
                                        @empty
                                            <div class="px-3 py-2 text-[12px] text-neutral-500 dark:text-fg-dim">
                                                No keys match the search.
                                            </div>
                                        @endforelse
                                    </div>
                                    <p class="text-[11px] text-neutral-500 dark:text-fg-dim">
                                        Key names only — values stay in the secret manager. "Add as variable" creates
                                        <span class="font-mono">KEY={{ '{{vault.KEY}'.'}' }}</span>; you can also paste a reference
                                        into any variable value, including inside a longer string.
                                    </p>
                                @endif
                            @endif
                        </div>
                    @endif
                </div>
            @else
                @if ($link)
                    <div class="px-1 py-2 text-[13px] text-black dark:text-fg">
                        {{ $link->integrationToken->providerName() }}
                        <span class="text-neutral-500 dark:text-fg-dim">
                            {{ $link->integrationToken->name }} &middot; {{ $link->sourceSummary() }}
                        </span>
                    </div>
                @endif
            @endcan
        @endif
    </x-application.settings-section>
</div>
