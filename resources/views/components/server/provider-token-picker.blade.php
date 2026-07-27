@props([
    'provider',
    'providerLabel',
    'routeType' => null,
    'tokens',
])

<x-application.settings-section title="{{ $providerLabel }} account"
    description="Choose the cloud credential Coolify should use for this server." flush>
    @if ($tokens->isEmpty())
        <x-empty title="No {{ $providerLabel }} tokens"
            description="Add an API token to continue provisioning." size="sm">
            <x-slot:icon>
                <x-reicon name="keys" class="size-6" />
            </x-slot:icon>
            <x-slot:actions>
                <x-modal-input title="Add {{ $providerLabel }} Token">
                    <x-slot:content>
                        <button type="button"
                            class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                            <x-reicon name="plus" class="size-3.5" />
                            Add token
                        </button>
                    </x-slot:content>
                    <livewire:security.cloud-provider-token-form :modal_mode="true" :provider="$provider"
                        wire:key="new-server-provider-token-{{ $provider }}" />
                </x-modal-input>
            </x-slot:actions>
        </x-empty>
    @else
        <div class="grid grid-cols-1 gap-3 p-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($tokens as $token)
                <a wire:key="{{ $provider }}-token-{{ $token->id }}"
                    class="group flex min-h-28 min-w-0 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                    href="{{ route('server.create.token', ['type' => $routeType ?: $provider, 'token_uuid' => $token->uuid]) }}"
                    {{ wireNavigate() }}>
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                            <x-reicon name="keys" class="size-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="truncate text-[13px]! font-semibold! text-black dark:text-fg">
                                {{ $token->name ?? $providerLabel . ' token' }}
                            </h3>
                            <p class="mt-1 line-clamp-2 text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">
                                {{ $token->description ?: 'Use this token to provision the server.' }}
                            </p>
                        </div>
                    </div>
                    <div class="mt-auto flex justify-end pt-4">
                        <x-reicon name="arrow-right" class="size-3 text-neutral-400" />
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</x-application.settings-section>
