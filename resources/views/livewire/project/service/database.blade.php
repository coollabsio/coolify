<form wire:submit.prevent='submit'>
    <div class="flex items-center gap-2 pb-4">
        @if ($database->human_name)
            <h2>{{ Str::headline($database->human_name) }}</h2>
        @else
            <h2>{{ Str::headline($database->name) }}</h2>
        @endif
        <x-forms.button type="submit">Save</x-forms.button>
        <a target="_blank" href="{{ $database->documentation() }}">Documentation <x-external-link /></a>
    </div>
    <div class="flex gap-2">
        <x-forms.input label="Name" id="database.human_name" placeholder="Name"></x-forms.input>
        <x-forms.input label="Description" id="database.description"></x-forms.input>
    </div>
</form>
