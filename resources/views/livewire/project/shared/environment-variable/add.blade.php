<form class="flex flex-col w-full gap-2 rounded" wire:submit='submit'>
    <x-forms.input autofocus placeholder="NODE_ENV" id="key" label="Name" required />
    <x-forms.textarea x-show="$wire.is_multiline === true" x-cloak id="value" label="Value" required />
    <x-forms.input x-show="$wire.is_multiline === false" x-cloak placeholder="production" id="value"
        x-bind:label="$wire.is_multiline === false && 'Value'" required />
    @if (data_get($parameters, 'application_uuid'))
        <x-forms.checkbox id="is_build_time" label="Build Variable?" />
    @endif
    <x-forms.checkbox id="is_multiline" label="Is Multiline?" />
    @if (!$shared)
        <x-forms.checkbox id="is_literal"
            helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
            label="Is Literal?" />
    @endif
    <x-forms.button type="submit" @click="slideOverOpen=false">
        Save
    </x-forms.button>
</form>
