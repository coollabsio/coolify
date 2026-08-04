<div class="flex items-center gap-2">
    @can('update', $server)
        <x-modal-input buttonTitle="Edit" title="Edit Configuration">
            <livewire:server.proxy.new-dynamic-configuration :server_id="$server_id" :fileName="$fileName" :value="$value"
                :newFile="$newFile" wire:key="{{ $fileName }}" />
        </x-modal-input>
        <x-forms.button isError wire:click="delete('{{ $fileName }}')">Delete</x-forms.button>
    @endcan
</div>
