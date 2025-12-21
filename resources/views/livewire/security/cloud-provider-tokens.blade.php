<div>
    <h2>{{ __('security.cloud_provider_tokens') }}</h2>
    <div class="pb-4">{{ __('security.manage_cloud_tokens_helper') }}</div>

    <h3>{{ __('security.new_token') }}</h3>
    @can('create', App\Models\CloudProviderToken::class)
        <livewire:security.cloud-provider-token-form :modal_mode="false" />
    @endcan

    <h3 class="py-4">{{ __('security.saved_tokens') }}</h3>
    <div class="grid gap-2 lg:grid-cols-1">
        @forelse ($tokens as $savedToken)
            <div wire:key="token-{{ $savedToken->id }}"
                class="flex flex-col gap-1 p-2 border dark:border-coolgray-200 hover:no-underline">
                <div class="flex items-center gap-2">
                    <span class="px-2 py-1 text-xs font-bold rounded dark:bg-coolgray-300 dark:text-white">
                        {{ strtoupper($savedToken->provider) }}
                    </span>
                    <span class="font-bold dark:text-white">{{ $savedToken->name }}</span>
                </div>
                <div class="text-sm">{{ __('security.created') }} {{ $savedToken->created_at->diffForHumans() }}</div>

                <div class="flex gap-2 pt-2">
                    @can('view', $savedToken)
                        <x-forms.button wire:click="validateToken({{ $savedToken->id }})" type="button">
                            {{ __('security.validate') }}
                        </x-forms.button>
                    @endcan

                    @can('delete', $savedToken)
                        <x-modal-confirmation title="{{ __('modal.confirm_token_deletion') }}" isErrorButton buttonTitle="{{ __('modal.delete_token') }}"
                            submitAction="deleteToken({{ $savedToken->id }})" :actions="[
                                __('security.delete_token_warning_1'),
                                __('security.delete_token_warning_2'),
                            ]"
                            confirmationText="{{ $savedToken->name }}"
                            confirmationLabel="{{ __('security.confirm_delete_token_label') }}"
                            shortConfirmationLabel="{{ __('security.token_name') }}" :confirmWithPassword="false" step2ButtonText="{{ __('security.delete_token') }}" />
                    @endcan
                </div>
            </div>
        @empty
            <div>
                <div>{{ __('security.no_cloud_tokens') }}</div>
            </div>
        @endforelse
    </div>
</div>
