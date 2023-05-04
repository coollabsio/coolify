<div>
    @if ($isNewEnv === true)
        <form wire:submit.prevent='submit' class="flex gap-2 p-4">
        @else
            <form wire:submit.prevent='updateEnv' class="flex gap-2 p-4">
    @endif
    <input type="text" wire:model.defer="keyName" />
    <input type="text" wire:model.defer="value" />
    <div class="flex flex-col">
        <div class="flex items-center gap-2">
            <input type="checkbox" wire:model.defer="isBuildOnly" />
            <label>Used during build?</label>
        </div>
    </div>
    <x-inputs.button type="submit">
        @if ($isNewEnv)
            Add
        @else
            Update
        @endif
    </x-inputs.button>
    @if ($isNewEnv === false)
        <x-inputs.button isWarning wire:click.prevent='delete'>
            Delete
        </x-inputs.button>
    @endif
    </form>
</div>
