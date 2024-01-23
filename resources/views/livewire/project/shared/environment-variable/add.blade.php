<form class="flex flex-col gap-2 rounded" wire:submit='submit'>
    <x-forms.input placeholder="NODE_ENV" id="key" label="Name" required />
    <x-forms.input placeholder="production" id="value" label="Value" required />
    @if (data_get($parameters, 'application_uuid'))
        <x-forms.checkbox id="is_build_time" label="Build Variable?" />
    @endif
    <x-forms.button type="submit" @click="slideOverOpen=false">
        Save
    </x-forms.button>
</form>
