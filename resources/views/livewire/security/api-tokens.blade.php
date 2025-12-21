<div>
    <x-slot:title>
        {{ __('security.api_tokens') }} | Coolify
    </x-slot>
    <x-security.navbar />
    <div class="pb-4">
        <h2>{{ __('security.api_tokens') }}</h2>
        @if (!$isApiEnabled)
            <div>{!! __('security.api_disabled', ['url' => route('settings.advanced')]) !!}</div>
        @else
            <div>{{ __('security.scope_hint') }}</div>
    </div>
    <h3>{{ __('security.new_token') }}</h3>
    @can('create', App\Models\PersonalAccessToken::class)
        <form class="flex flex-col gap-2" wire:submit='addNewToken'>
            <div class="flex gap-2 items-end w-96">
                <x-forms.input required id="description" label="{{ __('security.description') }}" />
                <x-forms.button type="submit">{{ __('security.create') }}</x-forms.button>
            </div>
            <div class="flex">
                {{ __('security.permissions') }}
                <x-helper class="px-1" helper="{{ __('security.permissions_hint') }}" /><span
                    class="pr-1">:</span>
                <div class="flex gap-1 font-bold dark:text-white">
                    @if ($permissions)
                        @foreach ($permissions as $permission)
                            <div>{{ $permission }}</div>
                        @endforeach
                    @endif
                </div>
            </div>

            <h4>{{ __('security.token_permissions') }}</h4>
            <div class="w-64">
                @if ($canUseRootPermissions)
                    <x-forms.checkbox label="root" wire:model.live="permissions" domValue="root"
                        helper="{{ __('security.root_access_careful') }}" :checked="in_array('root', $permissions)"></x-forms.checkbox>
                @else
                    <x-forms.checkbox label="{{ __('security.root_admin_only') }}" disabled domValue="root"
                        helper="{{ __('security.root_helper') }}" :checked="false"></x-forms.checkbox>
                @endif

                @if (!in_array('root', $permissions))
                    @if ($canUseWritePermissions)
                        <x-forms.checkbox label="write" wire:model.live="permissions" domValue="write"
                            helper="{{ __('security.write_helper') }}" :checked="in_array('write', $permissions)"></x-forms.checkbox>
                    @else
                        <x-forms.checkbox label="{{ __('security.write_admin_only') }}" disabled domValue="write"
                            helper="{{ __('security.write_helper_admin') }}" :checked="false"></x-forms.checkbox>
                    @endif

                    <x-forms.checkbox label="deploy" wire:model.live="permissions" domValue="deploy"
                        helper="{{ __('security.deploy_helper') }}" :checked="in_array('deploy', $permissions)"></x-forms.checkbox>
                    <x-forms.checkbox label="read" domValue="read" wire:model.live="permissions" domValue="read"
                        :checked="in_array('read', $permissions)"></x-forms.checkbox>
                    <x-forms.checkbox label="read:sensitive" wire:model.live="permissions" domValue="read:sensitive"
                        helper="{{ __('security.read_sensitive_helper') }}"
                        :checked="in_array('read:sensitive', $permissions)"></x-forms.checkbox>
                @endif
            </div>
            @if (in_array('root', $permissions))
                <div class="font-bold dark:text-warning">{{ __('security.root_access_careful') }}</div>
            @endif
        </form>
    @endcan
    @if (session()->has('token'))
        <div class="py-4 font-bold dark:text-warning">{{ __('security.copy_token_hint') }}
        </div>
        <div class="pb-4 font-bold dark:text-white"> {{ session('token') }}</div>
    @endif
    <h3 class="py-4">{{ __('security.issued_tokens') }}</h3>
    <div class="grid gap-2 lg:grid-cols-1">
        @forelse ($tokens as $token)
            <div wire:key="token-{{ $token->id }}"
                class="flex flex-col gap-1 p-2 border dark:border-coolgray-200 hover:no-underline">
                <div>{{ __('security.description') }}: {{ $token->name }}</div>
                <div>{{ __('security.last_used') }} {{ $token->last_used_at ? $token->last_used_at->diffForHumans() : __('security.never') }}</div>
                <div class="flex gap-1">
                    @if ($token->abilities)
                        {{ __('security.permissions') }}:
                        @foreach ($token->abilities as $ability)
                            <div class="font-bold dark:text-white">{{ $ability }}</div>
                        @endforeach
                    @endif
                </div>

                @if (auth()->id() === $token->tokenable_id)
                    <x-modal-confirmation title="{{ __('modal.confirm_api_token_revocation') }}" isErrorButton buttonTitle="{{ __('modal.revoke_token') }}"
                        submitAction="revoke({{ data_get($token, 'id') }})" :actions="[
                            __('security.revoke_warning_1'),
                            __('security.revoke_warning_2'),
                        ]"
                        confirmationText="{{ $token->name }}"
                        confirmationLabel="{{ __('security.revoke_confirm_label') }}"
                        shortConfirmationLabel="{{ __('security.revoke_short_label') }}" :confirmWithPassword="false"
                        step2ButtonText="{{ __('security.revoke_button') }}" />
                @endif
            </div>
        @empty
            <div>
                <div>{{ __('security.no_tokens_found') }}</div>
            </div>
        @endforelse
    </div>
    @endif
</div>
