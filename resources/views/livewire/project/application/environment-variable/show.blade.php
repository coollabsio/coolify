<div x-data="{ deleteEnvironment: false }">
    <form wire:submit.prevent='submit' class="flex gap-2 px-2">
        <input type="text" wire:model.defer="env.key" wire:dirty.class="text-black bg-amber-300" />
        <input type="text" wire:model.defer="env.value" wire:dirty.class="text-black bg-amber-300" />
        <div class="flex flex-col">
            <div class="flex items-center gap-2">
                <input type="checkbox" wire:model.defer="env.is_build_time" />
                <label wire:dirty.class="text-amber-300" wire:target="env.is_build_time">Build Variable?</label>
            </div>
        </div>
        <x-inputs.button type="submit">
            Update
        </x-inputs.button>
        <x-inputs.button x-on:click="deleteEnvironment = true" isWarning>
            Delete
        </x-inputs.button>
    </form>
    <x-naked-modal show="deleteEnvironment" message="Are you sure you want to delete {{ $env->key }}?" />
</div>
