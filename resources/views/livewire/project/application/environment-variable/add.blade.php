<form wire:submit.prevent='submit' class="flex flex-col px-2 max-w-fit">
    <div class="flex gap-2">
        <x-inputs.input noDirty id="key" label="Name" required />
        <x-inputs.input noDirty id="value" label="Value" required />
        <x-inputs.input noDirty type="checkbox" id="is_build_time" label="Build Variable?" />
    </div>
    <div class="pt-2">
        <x-inputs.button isBold type="submit">
            Add
        </x-inputs.button>
    </div>
</form>
