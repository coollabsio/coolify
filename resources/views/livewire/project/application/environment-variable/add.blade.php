<form wire:submit.prevent='submit' class="flex flex-col max-w-fit">
    <div class="flex items-end justify-center gap-2">
        <x-forms.input placeholder="NODE_ENV" noDirty id="key" label="Name" required />
        <x-forms.input placeholder="production" noDirty id="value" label="Value" required />
        <x-forms.checkbox noDirty class="flex-col text-center w-96" id="is_build_time" label="Build Variable?" />
        <x-forms.button type="submit">
            Add New Variable
        </x-forms.button>
    </div>
</form>
