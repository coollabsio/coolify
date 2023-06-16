<div x-data="{ deleteEnvironment: false }">
    <form wire:submit.prevent='submit' class="flex flex-col gap-2 xl:items-end xl:flex-row">
        <x-forms.input id="env.key" label="Name" />
        <x-forms.input type="password" id="env.value" label="Value" />
        <x-forms.checkbox disabled id="env.is_build_time" label="Build Variable?" />
        <div class="flex gap-2">
            <x-forms.button type="submit">
                Update
            </x-forms.button>
            <x-forms.button x-on:click.prevent="deleteEnvironment = true">
                Delete
            </x-forms.button>
        </div>
    </form>
    <x-naked-modal show="deleteEnvironment" message="Are you sure you want to delete {{ $env->key }}?" />
</div>
