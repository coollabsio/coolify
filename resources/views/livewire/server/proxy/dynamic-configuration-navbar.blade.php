<div class="flex gap-2">
    <h3 class="dark:text-white">File: {{ str_replace('|', '.', $fileName) }}</h3>
    @can('update', $server)
        <div class="flex gap-2">
            <x-modal-input buttonTitle="{{ __('common.edit') }}" title="{{ __('modal.edit_configuration') }}">
                <livewire:server.proxy.new-dynamic-configuration :server_id="$server_id" :fileName="$fileName" :value="$value"
                    :newFile="$newFile" wire:key="{{ $fileName }}" />
            </x-modal-input>
        </div>
        <x-forms.button isError wire:click="delete('{{ $fileName }}')">{{ __('button.delete') }}</x-forms.button>
    @endcan
</div>
