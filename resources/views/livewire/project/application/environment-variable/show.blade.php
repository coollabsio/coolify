<div x-data="{ deleteEnvironment: false }">
    <form wire:submit.prevent='submit' class="flex gap-2 px-2">
        <x-inputs.input id="env.key" noLabel />
        <x-inputs.input id="env.value" noLabel />
        <div class="flex flex-col">
            <div class="flex items-center gap-2">
                <x-inputs.input type="checkbox" id="env.is_build_time" label="Build Variable?" />
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
