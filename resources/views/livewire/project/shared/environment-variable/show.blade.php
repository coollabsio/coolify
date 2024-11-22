<div>
    <form wire:submit='submit'
        @class([
            'flex flex-col items-center gap-4 p-4 bg-white border lg:items-start dark:bg-base',
            'border-error' => $env->is_really_required,
            'dark:border-coolgray-300' => !$env->is_really_required,
        ])
        >
        @if ($isLocked)
            <div class="flex flex-1 w-full gap-2">
                <x-forms.input disabled id="env.key" />
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                        stroke-width="2">
                        <path d="M5 13a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6z" />
                        <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0-2 0m-3-5V7a4 4 0 1 1 8 0v4" />
                    </g>
                </svg>
                <x-modal-confirmation title="Confirm Environment Variable Deletion?" isErrorButton buttonTitle="Delete"
                    submitAction="delete" :actions="['The selected environment variable will be permanently deleted.']" confirmationText="{{ $env->key }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the Environment Variable Name below"
                    shortConfirmationLabel="Environment Variable Name" :confirmWithPassword="false"
                    step2ButtonText="Permanently Delete" />
            </div>
        @else
            @if ($isDisabled)
                <div class="flex flex-col w-full gap-2 lg:flex-row">
                    <x-forms.input disabled id="env.key" />
                    <x-forms.input disabled type="password" id="env.value" />
                    @if ($env->is_shared)
                        <x-forms.input disabled type="password" id="env.real_value" />
                    @endif
                </div>
            @else
                <div class="flex flex-col w-full gap-2 lg:flex-row">
                    @if ($env->is_multiline)
                        <x-forms.input isMultiline="{{ $env->is_multiline }}" id="env.key" />
                        <x-forms.textarea type="password" id="env.value" />
                    @else
                        <x-forms.input id="env.key" />
                        <x-forms.input type="password" id="env.value" />
                    @endif
                    @if ($env->is_shared)
                        <x-forms.input disabled type="password" id="env.real_value" />
                    @endif
                </div>
            @endif
            <div class="flex flex-col w-full gap-2 lg:flex-row">
                @if ($type === 'service')
                    <x-forms.checkbox instantSave id="env.is_build_time"
                        helper="If you are using Docker, remember to modify the file to be ready to receive the build time args. Ex.: for docker file, add `ARG name_of_the_variable`, or dockercompose add `- 'name_of_the_variable=${name_of_the_variable}'`"
                        label="Build Variable?" />
                @else
                    @if ($env->is_shared)
                        <x-forms.checkbox instantSave id="env.is_build_time"
                            helper="If you are using Docker, remember to modify the file to be ready to receive the build time args. Ex.: for docker file, add `ARG name_of_the_variable`, or dockercompose add `- 'name_of_the_variable=${name_of_the_variable}'`"
                            label="Build Variable?" />
                        <x-forms.checkbox instantSave id="env.is_literal"
                            helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                            label="Is Literal?" />
                    @else
                        @if ($isSharedVariable)
                            <x-forms.checkbox instantSave id="env.is_multiline" label="Is Multiline?" />
                        @else
                            <x-forms.checkbox instantSave id="env.is_build_time"
                                helper="If you are using Docker, remember to modify the file to be ready to receive the build time args. Ex.: for dockerfile, add `ARG name_of_the_variable`, or dockercompose add `- 'name_of_the_variable=${name_of_the_variable}'`"
                                label="Build Variable?" />
                            <x-forms.checkbox instantSave id="env.is_multiline" label="Is Multiline?" />
                            @if (!data_get($env, 'is_multiline'))
                                <x-forms.checkbox instantSave id="env.is_literal"
                                    helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                                    label="Is Literal?" />
                            @endif
                        @endif
                    @endif
                @endif
                <div class="flex-1"></div>
                @if ($isDisabled)
                    <x-forms.button disabled type="submit">
                        Update
                    </x-forms.button>
                    <x-forms.button wire:click='lock'>
                        Lock
                    </x-forms.button>
                    <x-modal-confirmation title="Confirm Environment Variable Deletion?" isErrorButton
                        buttonTitle="Delete" submitAction="delete" :actions="['The selected environment variable will be permanently deleted.']"
                        confirmationText="{{ $env->key }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the Environment Variable Name below"
                        shortConfirmationLabel="Environment Variable Name" :confirmWithPassword="false"
                        step2ButtonText="Permanently Delete" />
                @else
                    <x-forms.button type="submit">
                        Update
                    </x-forms.button>
                    <x-forms.button wire:click='lock'>
                        Lock
                    </x-forms.button>
                    <x-modal-confirmation title="Confirm Environment Variable Deletion?" isErrorButton
                        buttonTitle="Delete" submitAction="delete" :actions="['The selected environment variable will be permanently deleted.']"
                        confirmationText="{{ $env->key }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the Environment Variable Name below"
                        shortConfirmationLabel="Environment Variable Name" :confirmWithPassword="false"
                        step2ButtonText="Permanently Delete" />
                @endif
            </div>
        @endif

    </form>
</div>
