<form wire:submit.prevent='submit' class="flex items-end gap-2 px-2">
    <x-inputs.input noDirty id="name" label="Name" required />
    <x-inputs.input noDirty id="mount_path" label="Mount Path (in your app)" required />
    <x-inputs.input noDirty id="host_path" label="Mount Path (host)" />

    <x-inputs.button type="submit">
        Add
    </x-inputs.button>
</form>
