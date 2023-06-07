<form wire:submit.prevent='submit' class="flex flex-col w-full px-2">
    <div class="flex items-end gap-2">
        <x-forms.input placeholder="pv-name" noDirty id="name" label="Name" required />
        <x-forms.input placeholder="/root" noDirty id="host_path" label="Source Path" />
        <x-forms.input placeholder="/tmp/root" noDirty id="mount_path" label="Destination Path" required />
        <x-forms.button type="submit">
            Add New Volume
        </x-forms.button>
    </div>
</form>
