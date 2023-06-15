<form wire:submit.prevent='submit' class="flex flex-col gap-2 xl:items-end xl:flex-row">
    <x-forms.input placeholder="pv-name" noDirty id="name" label="Name" required />
    <x-forms.input placeholder="/root" noDirty id="host_path" label="Source Path" />
    <x-forms.input placeholder="/tmp/root" noDirty id="mount_path" label="Destination Path" required />
    <x-forms.button type="submit">
        Add New Volume
    </x-forms.button>
</form>
