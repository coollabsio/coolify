<form wire:submit.prevent='submit' class="flex flex-col px-2 pt-10 max-w-fit">
    <div class="flex gap-2">
        <x-inputs.input noDirty id="name" label="Name" required />
        <x-inputs.input noDirty id="host_path" label="Source Path" />
        <x-inputs.input noDirty id="mount_path" label="Destination Path" required />
    </div>
    <div class="pt-2">
        <x-inputs.button isBold type="submit">
            Add
        </x-inputs.button>
    </div>
</form>
