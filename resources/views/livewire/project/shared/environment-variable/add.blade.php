<form class="flex flex-col w-full gap-2 rounded-sm" wire:submit='submit'>
    <x-forms.input placeholder="NODE_ENV" id="key" label="Name" required />
    @if ($is_multiline)
        <x-forms.textarea id="value" label="Value" required />
    @else
        <x-forms.env-var-input placeholder="production" id="value" label="Value" required
            :availableVars="$shared ? [] : $this->availableSharedVariables"
            :projectUuid="data_get($parameters, 'project_uuid')"
            :environmentUuid="data_get($parameters, 'environment_uuid')" />
    @endif

    @if (!$shared && !$is_multiline)
        <div class="text-xs text-neutral-500 dark:text-neutral-400 -mt-1">
            Tip: Type <span class="font-mono dark:text-warning text-coollabs">{{</span> to reference a shared environment
            variable
        </div>
    @endif

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