<div class="flex gap-2">
    <div class="flex-1"></div>

    {{--    @if (data_get($execution, 'status') !== 'failed') --}}
    {{--        <x-forms.button class="bg-coollabs-100 hover:bg-coollabs" wire:click="download">Download</x-forms.button> --}}
    {{--    @endif --}}
    <x-forms.button isError wire:click="delete">Delete</x-forms.button>
</div>
