<div>
    <h2 class="py-4">OAuth Providers</h2>
    @if (empty($providers))
        <div class="text-sm dark:text-neutral-400">
            No OAuth providers are enabled on this instance.
        </div>
    @else
        <div class="text-xs font-bold dark:text-warning pb-3">
            For your security, only OAuth accounts you connect here can sign you in. After upgrading from older
            Coolify versions, OAuth-only users (without a password) will be linked automatically on their next
            verified login.
        </div>
        <div class="flex flex-col gap-2">
            @foreach ($providers as $provider)
                <div
                    class="flex items-center justify-between p-3 border dark:border-coolgray-300 border-neutral-200">
                    <div class="flex flex-col">
                        <div class="font-medium">{{ ucfirst($provider['provider']) }}</div>
                        @if ($provider['linked'])
                            <div class="text-xs dark:text-neutral-400">
                                Linked (provider id: {{ $provider['provider_user_id'] }})
                            </div>
                        @else
                            <div class="text-xs dark:text-neutral-400">Not connected</div>
                        @endif
                    </div>
                    @if ($provider['linked'])
                        <x-forms.button type="button" isError
                            wire:click="disconnect({{ $provider['link_id'] }})">
                            Disconnect
                        </x-forms.button>
                    @else
                        <x-forms.button type="button"
                            wire:click="connect('{{ $provider['provider'] }}')">
                            Connect
                        </x-forms.button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
