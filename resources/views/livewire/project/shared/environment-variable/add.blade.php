<form class="flex flex-col w-full gap-2 rounded" wire:submit='submit'>
    <x-forms.input autofocus placeholder="NODE_ENV" id="key" label="Name" required />
    <x-forms.textarea x-show="$wire.is_multiline === true" x-cloak id="value" label="Value" required />
    <x-forms.input x-show="$wire.is_multiline === false" x-cloak placeholder="production" id="value"
        x-bind:label="$wire.is_multiline === false && 'Value'" required />
    @if (data_get($parameters, 'application_uuid'))
        <x-forms.checkbox id="is_build_time" label="Build Variable?" />
    @endif
    <x-forms.checkbox id="is_multiline" label="Is Multiline?" />
    <x-forms.button type="submit" @click="slideOverOpen=false">
        Save
    </x-forms.button>
</form>
