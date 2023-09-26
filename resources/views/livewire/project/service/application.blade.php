<div>
    <form wire:submit.prevent='submit'>
        <div class="flex items-center gap-2 pb-4">
            @if ($application->human_name)
                <h2>{{ Str::headline($application->human_name) }}</h2>
            @else
                <h2>{{ Str::headline($application->name) }}</h2>
            @endif
            <x-forms.button type="submit">Save</x-forms.button>
            <x-forms.button isError wire:click='delete'>Delete</x-forms.button>
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex gap-2">
                <x-forms.input label="Name" id="application.human_name"
                    placeholder="Human readable name"></x-forms.input>
                <x-forms.input label="Description" id="application.description"></x-forms.input>
            </div>
            <div class="flex gap-2">
                @if ($application->required_fqdn)
                    <x-forms.input required placeholder="https://app.coolify.io" label="Domains"
                        id="application.fqdn"></x-forms.input>
                @else
                    <x-forms.input placeholder="https://app.coolify.io" label="Domains"
                        id="application.fqdn"></x-forms.input>
                @endif
                <x-forms.input required
                    helper="You can change the image you would like to deploy.<br><br><span class='text-warning'>WARNING. You could corrupt your data. Only do it if you know what you are doing.</span>"
                    label="Image" id="application.image"></x-forms.input>
            </div>
        </div>

        <h3 class="pt-2">Advanced</h3>
        <div class="w-64">
            <x-forms.checkbox instantSave label="Exclude from service status"
                helper="If you do not need to monitor this resource, enable. Useful if this service is optional."
                id="application.exclude_from_status"></x-forms.checkbox>
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
