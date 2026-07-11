<div class="flex flex-col gap-4">
    <x-slot:title>
        {{ $title }} | Coolify
    </x-slot>

    <div>
        <div class="flex items-center gap-2">
            <h1>{{ $title }}</h1>
            @if ($type)
                <a href="{{ $token_uuid ? route('server.create.type', ['type' => $type]) : route('server.create') }}" {{ wireNavigate() }}>
                    <x-forms.button>Back</x-forms.button>
                </a>
                @if (! $token_uuid && $hasProviderTokens && $tokenProvider)
                    @can('create', App\Models\CloudProviderToken::class)
                        <x-modal-input buttonTitle="+ New Token" title="Add {{ $tokenProviderName }} Token" isHighlightedButton>
                            <livewire:security.cloud-provider-token-form :modal_mode="true" :provider="$tokenProvider"
                                wire:key="new-server-header-token-{{ $tokenProvider }}" />
                        </x-modal-input>
                    @endcan
                @endif
            @endif
        </div>
        <div class="subtitle">Add a server to deploy your applications and databases.</div>
    </div>

    <div class="w-full">
        <livewire:server.create :selected-type="$type" :selected-token-uuid="$token_uuid" />
    </div>
</div>
