<div x-data="{ deleteEnvironment: false }">
    <x-naked-modal show="deleteEnvironment" title="Delete Environment"
        message='This environment will be deleted. It is not reversible. <br>Please think again.' />
    <x-forms.button isError x-on:click.prevent="deleteEnvironment = true">
        Delete Environment
    </x-forms.button>
</div>
