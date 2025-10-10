<form class="flex flex-col w-full gap-2 rounded-sm" wire:submit='submit'>
    <x-forms.input placeholder="NODE_ENV" id="key" label="Name" required />
    <x-forms.textarea x-show="$wire.is_multiline === true" x-cloak id="value" label="Value" required />
    <x-forms.input x-show="$wire.is_multiline === false" x-cloak placeholder="production" id="value"
        x-bind:label="$wire.is_multiline === false && 'Value'" required />

    @if (!$shared)
        <x-forms.checkbox id="is_buildtime"
            helper="Make this variable available during Docker build process. Useful for build secrets and dependencies."
            label="Available at Buildtime" />

        <x-environment-variable-warning :problematic-variables="$problematicVariables" />

        <x-forms.checkbox id="is_runtime" helper="Make this variable available in the running container at runtime."
            label="Available at Runtime" />
        <x-forms.checkbox id="is_literal"
            helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
            label="Is Literal?" />
    @endif

    <x-forms.checkbox id="is_multiline" label="Is Multiline?" />
    <x-forms.button type="submit" @click="slideOverOpen=false">
        Save
    </x-forms.button>
</form>
