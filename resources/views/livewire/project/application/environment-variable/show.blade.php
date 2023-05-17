<div x-data="{ deleteEnvironment: false }">
    <form wire:submit.prevent='submit' class="flex flex-col px-2 max-w-fit">
        <div class="flex gap-2">
            <x-inputs.input label="Name" id="env.key" />
            <x-inputs.input label="Value" id="env.value" />
            <x-inputs.input type="checkbox" id="env.is_build_time" label="Build Variable?" />
        </div>
        <div class="pt-2">
            <x-inputs.button isBold type="submit">
                Update
            </x-inputs.button>
            <x-inputs.button x-on:click.prevent="deleteEnvironment = true" isWarning>
                Delete
            </x-inputs.button>
        </div>
    </form>
    <x-naked-modal show="deleteEnvironment" message="Are you sure you want to delete {{ $env->key }}?" />
</div>
