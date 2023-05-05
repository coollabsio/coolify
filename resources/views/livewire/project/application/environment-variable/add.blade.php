<form wire:submit.prevent='submit' class="flex items-end gap-2 px-2">
    <x-inputs.input noDirty id="key" label="Name" required />
    <x-inputs.input noDirty id="value" label="Value" required />
    <div class="flex flex-col">
        <div class="flex items-center gap-2">
            <x-inputs.input noDirty type="checkbox" id="is_build_time" label="Build Variable?" />
        </div>
    </div>
    <x-inputs.button type="submit">
        Add
    </x-inputs.button>
</form>
