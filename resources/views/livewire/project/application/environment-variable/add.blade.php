<form wire:submit.prevent='submit' class="flex flex-col gap-2 xl:items-end xl:flex-row"">
    <x-forms.input placeholder="NODE_ENV" noDirty id="key" label="Name" required />
    <x-forms.input placeholder="production" noDirty id="value" label="Value" required />
    <x-forms.checkbox class="w-96" noDirty id="is_build_time" label="Build Variable?" />
    <x-forms.button type="submit">
        Add New Variable
    </x-forms.button>
</form>
