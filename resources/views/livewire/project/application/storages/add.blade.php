<form wire:submit.prevent='submit' class="flex flex-col px-2 pt-10 max-w-fit">
    <div class="flex gap-2">
        <x-forms.input placeholder="pv-name" noDirty id="name" label="Name" required />
        <x-forms.input placeholder="/root" noDirty id="host_path" label="Source Path" />
        <x-forms.input placeholder="/tmp/root" noDirty id="mount_path" label="Destination Path" required />
    </div>
    <div class="pt-2">
        <x-forms.button type="submit">
            Add
        </x-forms.button>
    </div>
</form>
