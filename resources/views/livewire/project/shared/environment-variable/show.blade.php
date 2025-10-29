<div>
    <form wire:submit='submit' @class([
        'flex flex-col items-center gap-4 p-4 bg-white border lg:items-start dark:bg-base',
        'border-error' => $is_really_required,
        'dark:border-coolgray-300 border-neutral-200' => !$is_really_required,
    ])>
        @if ($isLocked)
            <div class="flex flex-1 w-full gap-2">
                <x-forms.input disabled id="key" />
                <svg class="icon  my-1" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2">
                        <path d="M5 13a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6z" />
                        <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0-2 0m-3-5V7a4 4 0 1 1 8 0v4" />
                    </g>
                </svg>
                @can('delete', $this->env)
                    <x-modal-confirmation title="Confirm Environment Variable Deletion?" isErrorButton buttonTitle="Delete"
                        submitAction="delete" :actions="['The selected environment variable will be permanently deleted.']" confirmationText="{{ $env->key }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the Environment Variable Name below"
                        shortConfirmationLabel="Environment Variable Name" :confirmWithPassword="false"
                        step2ButtonText="Permanently Delete" />
                @endcan
            </div>
            @can('update', $this->env)
                <div class="flex flex-col w-full gap-3">
                    <div class="flex flex-wrap w-full items-center gap-4">
                        @if (!$is_redis_credential)
                            @if ($type === 'service')
                                <x-forms.checkbox instantSave id="is_buildtime"
                                    helper="Make this variable available during Docker build process. Useful for build secrets and dependencies."
                                    label="Available at Buildtime" />
                                <x-forms.checkbox instantSave id="is_runtime"
                                    helper="Make this variable available in the running container at runtime."
                                    label="Available at Runtime" />
                                <x-forms.checkbox instantSave id="is_multiline" label="Is Multiline?" />
                                <x-forms.checkbox instantSave id="is_literal"
                                    helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                                    label="Is Literal?" />
                            @else
                                @if ($is_shared)
                                    <x-forms.checkbox instantSave id="is_literal"
                                        helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                                        label="Is Literal?" />
                                @else
                                    @if ($isSharedVariable)
                                        <x-forms.checkbox instantSave id="is_multiline" label="Is Multiline?" />
                                    @else
                                        @if (!$env->is_nixpacks)
                                            <x-forms.checkbox instantSave id="is_buildtime"
                                                helper="Make this variable available during Docker build process. Useful for build secrets and dependencies."
                                                label="Available at Buildtime" />
                                        @endif
                                        <x-forms.checkbox instantSave id="is_runtime"
                                            helper="Make this variable available in the running container at runtime."
                                            label="Available at Runtime" />
                                        @if (!$env->is_nixpacks)
                                            <x-forms.checkbox instantSave id="is_multiline" label="Is Multiline?" />
                                            @if ($is_multiline === false)
                                                <x-forms.checkbox instantSave id="is_literal"
                                                    helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                                                    label="Is Literal?" />
                                            @endif
                                        @endif
                                    @endif
                                @endif
                            @endif
                        @endif
                    </div>
                </div>
            @else
                <div class="flex flex-col w-full gap-3">
                    <div class="flex flex-wrap w-full items-center gap-4">
                        @if (!$is_redis_credential)
                            @if ($type === 'service')
                                <x-forms.checkbox disabled id="is_buildtime"
                                    helper="Make this variable available during Docker build process. Useful for build secrets and dependencies."
                                    label="Available at Buildtime" />
                                <x-forms.checkbox disabled id="is_runtime"
                                    helper="Make this variable available in the running container at runtime."
                                    label="Available at Runtime" />
                                <x-forms.checkbox disabled id="is_multiline" label="Is Multiline?" />
                                <x-forms.checkbox disabled id="is_literal"
                                    helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                                    label="Is Literal?" />
                            @else
                                @if ($is_shared)
                                    <x-forms.checkbox disabled id="is_literal"
                                        helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                                        label="Is Literal?" />
                                @else
                                    @if ($isSharedVariable)
                                        <x-forms.checkbox disabled id="is_multiline" label="Is Multiline?" />
                                    @else
                                        <x-forms.checkbox disabled id="is_buildtime"
                                            helper="Make this variable available during Docker build process. Useful for build secrets and dependencies."
                                            label="Available at Buildtime" />
                                        <x-forms.checkbox disabled id="is_runtime"
                                            helper="Make this variable available in the running container at runtime."
                                            label="Available at Runtime" />
                                        <x-forms.checkbox disabled id="is_multiline" label="Is Multiline?" />
                                        @if ($is_multiline === false)
                                            <x-forms.checkbox disabled id="is_literal"
                                                helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                                                label="Is Literal?" />
                                        @endif
                                    @endif
                                @endif
                            @endif
                        @endif
                    </div>
                </div>
            @endcan
        @else
            @can('update', $this->env)
                @if ($isDisabled)
                    <div class="flex flex-col w-full gap-2 lg:flex-row">
                        <x-forms.input disabled id="key" />
                        <x-forms.input disabled type="password" id="value" />
                        @if ($is_shared)
                            <x-forms.input disabled type="password" id="real_value" />
                        @endif
                    </div>
                @else
                    <div class="flex flex-col w-full gap-2 lg:flex-row">
                        @if ($is_multiline)
                            <x-forms.input :required="$is_redis_credential" isMultiline="{{ $is_multiline }}" id="key" />
                            <x-forms.textarea :required="$is_redis_credential" type="password" id="value" />
                        @else
                            <x-forms.input :disabled="$is_redis_credential" :required="$is_redis_credential" id="key" />
                            <x-forms.input :required="$is_redis_credential" type="password" id="value" />
                        @endif
                        @if ($is_shared)
                            <x-forms.input :disabled="$is_redis_credential" :required="$is_redis_credential" disabled type="password" id="real_value" />
                        @endif
                    </div>
                @endif
            @else
                <div class="flex flex-col w-full gap-2 lg:flex-row">
                    <x-forms.input disabled id="key" />
                    <x-forms.input disabled type="password" id="value" />
                    @if ($is_shared)
                        <x-forms.input disabled type="password" id="real_value" />
                    @endif
                </div>
            @endcan
            @can('update', $this->env)
                <div class="flex flex-col w-full gap-3">
                    <div class="flex flex-wrap w-full items-center gap-4">
                        @if (!$is_redis_credential)
                            @if ($type === 'service')
                                <x-forms.checkbox instantSave id="is_buildtime"
                                    helper="Make this variable available during Docker build process. Useful for build secrets and dependencies."
                                    label="Available at Buildtime" />
                                <x-forms.checkbox instantSave id="is_runtime"
                                    helper="Make this variable available in the running container at runtime."
                                    label="Available at Runtime" />
                                <x-forms.checkbox instantSave id="is_multiline" label="Is Multiline?" />
                                <x-forms.checkbox instantSave id="is_literal"
                                    helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                                    label="Is Literal?" />
                            @else
                                @if ($is_shared)
                                    <x-forms.checkbox instantSave id="is_literal"
                                        helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                                        label="Is Literal?" />
                                @else
                                    @if ($isSharedVariable)
                                        <x-forms.checkbox instantSave id="is_multiline" label="Is Multiline?" />
                                    @else
                                        @if (!$env->is_nixpacks)
                                            <x-forms.checkbox instantSave id="is_buildtime"
                                                helper="Make this variable available during Docker build process. Useful for build secrets and dependencies."
                                                label="Available at Buildtime" />
                                        @endif
                                        <x-forms.checkbox instantSave id="is_runtime"
                                            helper="Make this variable available in the running container at runtime."
                                            label="Available at Runtime" />
                                        @if (!$env->is_nixpacks)
                                            <x-forms.checkbox instantSave id="is_multiline" label="Is Multiline?" />
                                            @if ($is_multiline === false)
                                                <x-forms.checkbox instantSave id="is_literal"
                                                    helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                                                    label="Is Literal?" />
                                            @endif
                                        @endif
                                    @endif
                                @endif
                            @endif
                        @endif
                    </div>
                    <x-environment-variable-warning :problematic-variables="$problematicVariables" />
                    <div class="flex w-full justify-end gap-2">
                        @if ($isDisabled)
                            <x-forms.button disabled type="submit">Update</x-forms.button>
                            <x-forms.button wire:click='lock'>Lock</x-forms.button>
                            <x-modal-confirmation title="Confirm Environment Variable Deletion?" isErrorButton
                                buttonTitle="Delete" submitAction="delete" :actions="['The selected environment variable will be permanently deleted.']"
                                confirmationText="{{ $key }}" buttonFullWidth="true"
                                confirmationLabel="Please confirm the execution of the actions by entering the Environment Variable Name below"
                                shortConfirmationLabel="Environment Variable Name" :confirmWithPassword="false"
                                step2ButtonText="Permanently Delete" />
                        @else
                            <x-forms.button type="submit">Update</x-forms.button>
                            <x-forms.button wire:click='lock'>Lock</x-forms.button>
                            <x-modal-confirmation title="Confirm Environment Variable Deletion?" isErrorButton
                                buttonTitle="Delete" submitAction="delete" :actions="['The selected environment variable will be permanently deleted.']"
                                confirmationText="{{ $key }}" buttonFullWidth="true"
                                confirmationLabel="Please confirm the execution of the actions by entering the Environment Variable Name below"
                                shortConfirmationLabel="Environment Variable Name" :confirmWithPassword="false"
                                step2ButtonText="Permanently Delete" />
                        @endif
                    </div>
                </div>
            @else
                <div class="flex flex-col w-full gap-3">
                    <div class="flex flex-wrap w-full items-center gap-4">
                        @if (!$is_redis_credential)
                            @if ($type === 'service')
                                <x-forms.checkbox disabled id="is_buildtime"
                                    helper="Make this variable available during Docker build process. Useful for build secrets and dependencies."
                                    label="Available at Buildtime" />
                                <x-forms.checkbox disabled id="is_runtime"
                                    helper="Make this variable available in the running container at runtime."
                                    label="Available at Runtime" />
                                <x-forms.checkbox disabled id="is_multiline" label="Is Multiline?" />
                                <x-forms.checkbox disabled id="is_literal"
                                    helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                                    label="Is Literal?" />
                            @else
                                @if ($is_shared)
                                    <x-forms.checkbox disabled id="is_literal"
                                        helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                                        label="Is Literal?" />
                                @else
                                    @if ($isSharedVariable)
                                        <x-forms.checkbox disabled id="is_multiline" label="Is Multiline?" />
                                    @else
                                        <x-forms.checkbox disabled id="is_buildtime"
                                            helper="Make this variable available during Docker build process. Useful for build secrets and dependencies."
                                            label="Available at Buildtime" />
                                        <x-forms.checkbox disabled id="is_runtime"
                                            helper="Make this variable available in the running container at runtime."
                                            label="Available at Runtime" />
                                        <x-forms.checkbox disabled id="is_multiline" label="Is Multiline?" />
                                        @if ($is_multiline === false)
                                            <x-forms.checkbox disabled id="is_literal"
                                                helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                                                label="Is Literal?" />
                                        @endif
                                    @endif
                                @endif
                            @endif
                        @endif
                    </div>
                </div>
            @endcan
        @endif

    </form>
</div>
