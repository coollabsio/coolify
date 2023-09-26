<div>
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
        <div class="flex flex-col gap-2">
            <div class="flex gap-2">
                <x-forms.input label="Name" id="database.human_name" placeholder="Name"></x-forms.input>
                <x-forms.input label="Description" id="database.description"></x-forms.input>
            </div>
            <div class="flex gap-2">
                <x-forms.input required helper="You can change the image you would like to deploy.<br><br><span class='text-warning'>WARNING. You could corrupt your data. Only do it if you know what you are doing.</span>" label="Image Tag"
                    id="database.image"></x-forms.input>
            </div>
        </div>
        <h3 class="pt-2">Advanced</h3>
        <div class="w-64">
            <x-forms.checkbox instantSave label="Exclude from service status"
                helper="If you do not need to monitor this resource, enable. Useful if this service is optional."
                id="database.exclude_from_status"></x-forms.checkbox>
        </div>
    </form>
    @if ($fileStorages->count() > 0)
        <h3 class="py-4">Mounted Files (binds)</h3>
        <div class="flex flex-col gap-4">
            @foreach ($fileStorages as $fileStorage)
                <livewire:project.service.file-storage :fileStorage="$fileStorage" wire:key="{{ $loop->index }}" />
            @endforeach
        </div>
    @endif
</div>
