<form wire:submit.prevent='submit' class="flex gap-2 px-2">
    <x-inputs.input noLabel noDirty id="key" required />
    <x-inputs.input noLabel noDirty id="value" required />
    <div class="flex flex-col">
        <div class="flex items-center gap-2">
            <input type="checkbox" wire:model.defer="is_build_time" />
            <label>Build Variable?</label>
        </div>
    </div>
    <x-inputs.button type="submit">
        Add
    </x-inputs.button>
</form>
