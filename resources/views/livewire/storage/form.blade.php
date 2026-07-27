<form wire:submit="submit" class="application-settings-form">
    <x-unsaved-bar action="submit" />

    <x-application.settings-section title="{{ $storage->name }}"
        description="S3-compatible destination used by database and volume backups.">
        <x-slot:actions>
            @can('validateConnection', $storage)
                <button type="button" class="button" wire:click="testConnection">
                    <x-reicon name="check-circle" class="size-3.5" />
                    Validate connection
                </button>
            @endcan
        </x-slot:actions>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input canGate="update" :canResource="$storage" label="Name" id="name" />
            <x-forms.input canGate="update" :canResource="$storage" label="Description" id="description" />
            <div class="lg:col-span-2">
                <x-forms.input canGate="update" :canResource="$storage" required label="Endpoint"
                    id="endpoint" />
            </div>
            <x-forms.input canGate="update" :canResource="$storage" required label="Bucket" id="bucket" />
            <x-forms.input canGate="update" :canResource="$storage" required label="Region" id="region" />
            @if ($isPasswordHiddenForMember)
                <x-forms.input label="Access key" disabled value="Hidden (only admins can view)" />
                <x-forms.input label="Secret key" disabled value="Hidden (only admins can view)" />
            @else
                <x-forms.input canGate="update" :canResource="$storage" required type="password"
                    label="Access key" id="key" />
                <x-forms.input canGate="update" :canResource="$storage" required type="password"
                    label="Secret key" id="secret" />
            @endif
        </div>
    </x-application.settings-section>
</form>
