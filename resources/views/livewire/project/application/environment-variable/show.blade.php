<div x-data="{ deleteEnvironment: false }">
    <x-naked-modal show="deleteEnvironment" title="Delete Environment"
        message='This environment will be deleted. It is not reversible. <br>Please think again.' />
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
</div>
