<div class="flex gap-2">
    <h3 class="text-white">File: {{ str_replace('|', '.', $fileName) }}</h3>
    <div class="flex gap-2">
        <x-slide-over>
            <x-slot:title>Edit Configuration</x-slot:title>
            <x-slot:content>
                <livewire:server.proxy.new-dynamic-configuration :server_id="$server_id" :fileName="$fileName" :value="$value"
                    :newFile="$newFile" wire:key="{{ $fileName }}" />
            </x-slot:content>
            <button @click="slideOverOpen=true"
                class="font-normal text-white normal-case border-none rounded btn btn-primary btn-sm no-animation">Edit</button>
        </x-slide-over>
    </div>
    <x-forms.button isError wire:click="delete('{{ $fileName }}')">Delete</x-forms.button>
</div>
