<form wire:submit.prevent='submit' class="flex flex-col max-w-fit">
    <div class="flex gap-2">
        <x-forms.input placeholder="NODE_ENV" noDirty id="key" label="Name" required />
        <x-forms.input placeholder="production" noDirty id="value" label="Value" required />
        <x-forms.checkbox noDirty class="flex-col items-center" id="is_build_time" label="Build Variable?" />
    </div>
    <div class="pt-2">
        <x-forms.button type="submit">
            Add
        </x-forms.button>
    </div>
</form>
