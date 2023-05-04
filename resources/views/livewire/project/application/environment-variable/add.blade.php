<form wire:submit.prevent='submit' class="flex gap-2 p-4">
    <input type="text" wire:model.defer="key" wire:dirty.class="text-black bg-amber-300" />
    <input type="text" wire:model.defer="value" wire:dirty.class="text-black bg-amber-300" />
    <div class="flex flex-col">
        <div class="flex items-center gap-2">
            <input type="checkbox" wire:model.defer="is_build_time" />
            <label>Used during build?</label>
        </div>
    </div>
    <x-inputs.button type="submit">
        Add
    </x-inputs.button>
</form>
