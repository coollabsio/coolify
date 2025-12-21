<div x-init="$wire.loadPublicKey()">
    <x-slot:title>
        {{ __('private_keys.private_key') }} | Coolify
    </x-slot>
    <x-security.navbar />
    <div x-data="{ showPrivateKey: false }">
        <form class="flex flex-col" wire:submit='changePrivateKey'>
            <div class="flex items-start gap-2">
                <h2 class="pb-4">{{ __('private_keys.private_key') }}</h2>
                <x-forms.button canGate="update" :canResource="$private_key" type="submit">
                    {{ __('common.save') }}
                </x-forms.button>
                @if (data_get($private_key, 'id') > 0)
                    @can('delete', $private_key)
                        <x-modal-confirmation title="{{ __('modal.confirm_private_key_deletion') }}" isErrorButton buttonTitle="{{ __('modal.delete_private_key') }}"
                            submitAction="delete({{ $private_key->id }})" :actions="[
                                __('private_keys.delete_warning_1'),
                                __('private_keys.delete_warning_2'),
                                __('private_keys.delete_warning_3'),
                            ]"
                            confirmationText="{{ $private_key->name }}"
                            confirmationLabel="{{ __('private_keys.confirm_delete_label') }}"
                            shortConfirmationLabel="{{ __('private_keys.private_key_name') }}" :confirmWithPassword="false"
                            step2ButtonText="{{ __('private_keys.delete_button') }}" />
                    @endcan
                @endif
            </div>
            <div class="flex flex-col gap-2">
                <div class="flex gap-2">
                    <x-forms.input canGate="update" :canResource="$private_key" id="name" label="{{ __('input.name') }}" required />
                    <x-forms.input canGate="update" :canResource="$private_key" id="description" label="{{ __('input.description') }}" />
                </div>
                <div>
                    <div class="flex items-end gap-2 py-2 ">
                        <div class="pl-1">{{ __('private_keys.public_key') }}</div>
                    </div>
                    <x-forms.input canGate="update" :canResource="$private_key" readonly id="public_key" />
                    <div class="flex items-end gap-2 py-2 ">
                        <div class="pl-1">{{ __('private_keys.private_key') }} <span class='text-helper'>*</span></div>
                        <div class="text-xs underline cursor-pointer dark:text-white" x-cloak x-show="!showPrivateKey"
                            x-on:click="showPrivateKey = true">
                            {{ __('private_keys.edit') }}
                        </div>
                        <div class="text-xs underline cursor-pointer dark:text-white" x-cloak x-show="showPrivateKey"
                            x-on:click="showPrivateKey = false">
                            {{ __('private_keys.hide') }}
                        </div>
                    </div>
                    @if ($isGitRelated)
                        <div class="w-48">
                            <x-forms.checkbox id="isGitRelated" disabled label="{{ __('private_keys.is_git_related') }}" />
                        </div>
                    @endif
                    <div x-cloak x-show="!showPrivateKey">
                        <x-forms.input canGate="update" :canResource="$private_key" allowToPeak="false" type="password" rows="10" id="privateKeyValue"
                            required disabled />
                    </div>
                    <div x-cloak x-show="showPrivateKey">
                        <x-forms.textarea canGate="update" :canResource="$private_key" rows="10" id="privateKeyValue" required />
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
