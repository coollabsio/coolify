<div>
    <form class="flex flex-col gap-2 pb-6" wire:submit='submit'>
        <div class="flex items-start gap-2">
            <div class="">
                <h1>{{ __('storage.storage_details') }}</h1>
                <div class="subtitle">{{ $storage->name }}</div>
                <div class="flex items-center gap-2 pb-4">
                    <div>{{ __('storage.current_status') }}</div>
                    @if ($isUsable)
                        <span
                            class="px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded dark:text-green-100 dark:bg-green-800">
                            {{ __('storage.usable') }}
                        </span>
                    @else
                        <span
                            class="px-2 py-1 text-xs font-semibold text-red-800 bg-red-100 rounded dark:text-red-100 dark:bg-red-800">
                            {{ __('storage.not_usable') }}
                        </span>
                    @endif
                </div>
            </div>
            <x-forms.button canGate="update" :canResource="$storage" type="submit">{{ __('common.save') }}</x-forms.button>

            @can('delete', $storage)
                <x-modal-confirmation title="{{ __('modal.confirm_storage_deletion') }}" isErrorButton buttonTitle="{{ __('modal.delete_storage') }}"
                    submitAction="delete({{ $storage->id }})" :actions="[
                        __('storage.delete_storage_action_1'),
                        __('storage.delete_storage_action_2'),
                    ]" confirmationText="{{ $storage->name }}"
                    confirmationLabel="{{ __('modal.storage_name_confirmation') }}"
                    shortConfirmationLabel="{{ __('storage.name') }}" :confirmWithPassword="false" step2ButtonText="{{ __('button.permanently_delete') }}" />
            @endcan
        </div>
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$storage" label="{{ __('input.name') }}" id="name" />
            <x-forms.input canGate="update" :canResource="$storage" label="{{ __('common.description') }}" id="description" />
        </div>
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$storage" required label="{{ __('storage.endpoint') }}" id="endpoint" />
            <x-forms.input canGate="update" :canResource="$storage" required label="{{ __('storage.bucket') }}" id="bucket" />
            <x-forms.input canGate="update" :canResource="$storage" required label="{{ __('storage.region') }}" id="region" />
        </div>
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$storage" required type="password" label="{{ __('storage.access_key') }}"
                id="key" />
            <x-forms.input canGate="update" :canResource="$storage" required type="password" label="{{ __('storage.secret_key') }}"
                id="secret" />
        </div>
        @can('validateConnection', $storage)
            <x-forms.button class="mt-4" isHighlighted wire:click="testConnection">
                {{ __('storage.validate_connection') }}
            </x-forms.button>
        @endcan
    </form>
</div>
