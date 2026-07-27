<div class="application-settings-form">
    <x-application.settings-section title="Cloud tokens" flush>
        @if ($tokens->isEmpty())
            <x-empty title="No cloud tokens"
                description="Add a provider token to provision new cloud servers." size="sm">
                <x-slot:icon>
                    <x-reicon name="keys" class="size-6" />
                </x-slot:icon>
            </x-empty>
        @else
            <div class="grid grid-cols-1 gap-3 p-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($tokens as $savedToken)
                    <a wire:key="cloud-token-{{ $savedToken->id }}"
                        class="group flex min-h-28 min-w-0 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                        href="{{ route('security.cloud-tokens.show', ['cloud_token_uuid' => $savedToken->uuid]) }}"
                        {{ wireNavigate() }}>
                        <div class="flex min-w-0 items-start gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                <x-reicon name="keys" class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                    {{ $savedToken->name }}
                                </h3>
                                <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                    {{ $savedToken->description ?: 'No description' }}
                                </p>
                            </div>
                        </div>
                        <div class="mt-auto pt-4">
                            <span
                                class="inline-flex rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                {{ $savedToken->provider === 'digitalocean' ? 'DigitalOcean' : ucfirst($savedToken->provider) }}
                            </span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </x-application.settings-section>
</div>
