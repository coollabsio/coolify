<div>
    <form wire:submit.prevent='submit'>
        <div class="flex items-center gap-2 pb-4">
            @if ($application->human_name)
                <h2>{{ Str::headline($application->human_name) }}</h2>
            @else
                <h2>{{ Str::headline($application->name) }}</h2>
            @endif
            <x-forms.button type="submit">Save</x-forms.button>
            <a target="_blank" href="{{ $application->documentation() }}">Documentation <x-external-link /></a>
        </div>
        <div class="flex gap-2">
            <x-forms.input label="Name" id="application.human_name" placeholder="Human readable name"></x-forms.input>
            <x-forms.input label="Description" id="application.description"></x-forms.input>
            <x-forms.input placeholder="https://app.coolify.io" label="Domains" required
                id="application.fqdn"></x-forms.input>
        </div>
    </form>
    @if ($application->fileStorages()->get()->count() > 0)
        <h3 class="py-4">File Storages</h3>
        <div class="flex flex-col gap-4">
            @foreach ($application->fileStorages()->get() as $fileStorage)
                <livewire:project.service.file-storage :fileStorage="$fileStorage" wire:key="{{ $loop->index }}" />
            @endforeach
        </div>
    @endif
</div>
