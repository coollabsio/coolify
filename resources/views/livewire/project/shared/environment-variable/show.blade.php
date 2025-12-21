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
                    <x-modal-confirmation title="{{ __('env_var.confirm_delete_title') }}" isErrorButton buttonTitle="{{ __('button.delete') }}"
                        submitAction="delete" :actions="[__('env_var.delete_action')]" confirmationText="{{ $env->key }}"
                        confirmationLabel="{{ __('env_var.confirm_delete_label') }}"
                        shortConfirmationLabel="{{ __('env_var.variable_name') }}" :confirmWithPassword="false"
                        step2ButtonText="{{ __('button.permanently_delete') }}" />
                @endcan
            </div>
            @can('update', $this->env)
                <div class="flex flex-col w-full gap-3">
                    <div class="flex flex-wrap w-full items-center gap-4">
                        @if (!$is_redis_credential)
                            @if ($type === 'service')
                                <x-forms.checkbox instantSave id="is_buildtime"
                                    helper="{{ __('env_var.buildtime_helper') }}"
                                    label="{{ __('env_var.buildtime_label') }}" />
                                <x-forms.checkbox instantSave id="is_runtime"
                                    helper="{{ __('env_var.runtime_helper') }}"
                                    label="{{ __('env_var.runtime_label') }}" />
                                <x-forms.checkbox instantSave id="is_multiline" label="{{ __('env_var.multiline_label') }}" />
                                <x-forms.checkbox instantSave id="is_literal"
                                    helper="{{ __('env_var.literal_helper') }}"
                                    label="{{ __('env_var.literal_label') }}" />
                            @else
                                @if ($isSharedVariable)
                                    <x-forms.checkbox instantSave id="is_multiline" label="{{ __('env_var.multiline_label') }}" />
                                @else
                                    @if (!$env->is_nixpacks)
                                        <x-forms.checkbox instantSave id="is_buildtime"
                                            helper="{{ __('env_var.available_at_buildtime_helper') }}"
                                            label="{{ __('env_var.available_at_buildtime') }}" />
                                    @endif
                                    <x-forms.checkbox instantSave id="is_runtime"
                                        helper="{{ __('env_var.available_at_runtime_helper') }}"
                                        label="{{ __('env_var.available_at_runtime') }}" />
                                    @if (!$env->is_nixpacks)
                                        <x-forms.checkbox instantSave id="is_multiline" label="{{ __('env_var.multiline_label') }}" />
                                        @if ($is_multiline === false)
                                            <x-forms.checkbox instantSave id="is_literal"
                                                helper="{{ __('env_var.is_literal_helper') }}"
                                                label="{{ __('env_var.is_literal_label') }}" />
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
                                    helper="{{ __('env_var.buildtime_helper') }}"
                                    label="{{ __('env_var.buildtime_label') }}" />
                                <x-forms.checkbox disabled id="is_runtime"
                                    helper="{{ __('env_var.runtime_helper') }}"
                                    label="{{ __('env_var.runtime_label') }}" />
                                <x-forms.checkbox disabled id="is_multiline" label="{{ __('env_var.is_multiline') }}" />
                                <x-forms.checkbox disabled id="is_literal"
                                    helper="{{ __('env_var.literal_helper') }}"
                                    label="{{ __('env_var.literal_label') }}" />
                            @else
                                @if ($isSharedVariable)
                                    <x-forms.checkbox disabled id="is_multiline" label="{{ __('env_var.multiline_label') }}" />
                                @else
                                    @if (!$env->is_nixpacks)
                                        <x-forms.checkbox disabled id="is_buildtime"
                                            helper="{{ __('env_var.available_at_buildtime_helper') }}"
                                            label="{{ __('env_var.available_at_buildtime') }}" />
                                    @endif
                                    <x-forms.checkbox disabled id="is_runtime"
                                        helper="{{ __('env_var.available_at_runtime_helper') }}"
                                        label="{{ __('env_var.available_at_runtime') }}" />
                                    <x-forms.checkbox disabled id="is_multiline" label="{{ __('env_var.multiline_label') }}" />
                                    @if ($is_multiline === false)
                                        <x-forms.checkbox disabled id="is_literal"
                                            helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                                            label="{{ __('env_var.is_literal_label') }}" />
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
                        <x-forms.env-var-input
                            disabled
                            type="password"
                            id="value"
                            :availableVars="$this->availableSharedVariables"
                            :projectUuid="data_get($parameters, 'project_uuid')"
                            :environmentUuid="data_get($parameters, 'environment_uuid')" />
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
                            <x-forms.env-var-input
                                :required="$is_redis_credential"
                                type="password"
                                id="value"
                                :availableVars="$this->availableSharedVariables"
                                :projectUuid="data_get($parameters, 'project_uuid')"
                                :environmentUuid="data_get($parameters, 'environment_uuid')" />
                        @endif
                        @if ($is_shared)
                            <x-forms.input :disabled="$is_redis_credential" :required="$is_redis_credential" disabled type="password" id="real_value" />
                        @endif
                    </div>
                @endif
            @else
                <div class="flex flex-col w-full gap-2 lg:flex-row">
                    <x-forms.input disabled id="key" />
                    <x-forms.env-var-input
                        disabled
                        type="password"
                        id="value"
                        :availableVars="$this->availableSharedVariables"
                        :projectUuid="data_get($parameters, 'project_uuid')"
                        :environmentUuid="data_get($parameters, 'environment_uuid')" />
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
                                    helper="{{ __('env_var.buildtime_helper') }}"
                                    label="{{ __('env_var.buildtime_label') }}" />
                                <x-forms.checkbox instantSave id="is_runtime"
                                    helper="{{ __('env_var.runtime_helper') }}"
                                    label="{{ __('env_var.runtime_label') }}" />
                                <x-forms.checkbox instantSave id="is_multiline" label="{{ __('env_var.multiline_label') }}" />
                                <x-forms.checkbox instantSave id="is_literal"
                                    helper="{{ __('env_var.literal_helper') }}"
                                    label="{{ __('env_var.literal_label') }}" />
                            @else
                                @if ($isSharedVariable)
                                    <x-forms.checkbox instantSave id="is_multiline" label="{{ __('env_var.multiline_label') }}" />
                                @else
                                    @if (!$env->is_nixpacks)
                                        <x-forms.checkbox instantSave id="is_buildtime"
                                            helper="{{ __('env_var.available_at_buildtime_helper') }}"
                                            label="{{ __('env_var.available_at_buildtime') }}" />
                                    @endif
                                    <x-forms.checkbox instantSave id="is_runtime"
                                        helper="{{ __('env_var.available_at_runtime_helper') }}"
                                        label="{{ __('env_var.available_at_runtime') }}" />
                                    @if (!$env->is_nixpacks)
                                        <x-forms.checkbox instantSave id="is_multiline" label="{{ __('env_var.multiline_label') }}" />
                                        @if ($is_multiline === false)
                                            <x-forms.checkbox instantSave id="is_literal"
                                                helper="{{ __('env_var.is_literal_helper') }}"
                                                label="{{ __('env_var.is_literal_label') }}" />
                                        @endif
                                    @endif
                                @endif
                            @endif
                        @endif
                    </div>
                    <x-environment-variable-warning :problematic-variables="$problematicVariables" />
                    <div class="flex w-full justify-end gap-2">
                        @if ($isDisabled)
                            <x-forms.button disabled type="submit">{{ __('button.update') }}</x-forms.button>
                            <x-forms.button wire:click='lock'>{{ __('env_var.lock_button') }}</x-forms.button>
                            <x-modal-confirmation title="{{ __('env_var.confirm_delete_title') }}" isErrorButton
                                buttonTitle="{{ __('button.delete') }}" submitAction="delete" :actions="[__('env_var.delete_action')]"
                                confirmationText="{{ $key }}" buttonFullWidth="true"
                                confirmationLabel="{{ __('env_var.confirm_delete_label') }}"
                                shortConfirmationLabel="{{ __('env_var.variable_name') }}" :confirmWithPassword="false"
                                step2ButtonText="{{ __('button.permanently_delete') }}" />
                        @else
                            <x-forms.button type="submit">{{ __('button.update') }}</x-forms.button>
                            <x-forms.button wire:click='lock'>{{ __('env_var.lock_button') }}</x-forms.button>
                            <x-modal-confirmation title="{{ __('env_var.confirm_delete_title') }}" isErrorButton
                                buttonTitle="{{ __('button.delete') }}" submitAction="delete" :actions="[__('env_var.delete_action')]"
                                confirmationText="{{ $key }}" buttonFullWidth="true"
                                confirmationLabel="{{ __('env_var.confirm_delete_label') }}"
                                shortConfirmationLabel="{{ __('env_var.variable_name') }}" :confirmWithPassword="false"
                                step2ButtonText="{{ __('button.permanently_delete') }}" />
                        @endif
                    </div>
                </div>
            @else
                <div class="flex flex-col w-full gap-3">
                    <div class="flex flex-wrap w-full items-center gap-4">
                        @if (!$is_redis_credential)
                            @if ($type === 'service')
                                <x-forms.checkbox disabled id="is_buildtime"
                                    helper="{{ __('env_var.buildtime_helper') }}"
                                    label="{{ __('env_var.buildtime_label') }}" />
                                <x-forms.checkbox disabled id="is_runtime"
                                    helper="{{ __('env_var.runtime_helper') }}"
                                    label="{{ __('env_var.runtime_label') }}" />
                                <x-forms.checkbox disabled id="is_multiline" label="{{ __('env_var.is_multiline') }}" />
                                <x-forms.checkbox disabled id="is_literal"
                                    helper="{{ __('env_var.literal_helper') }}"
                                    label="{{ __('env_var.literal_label') }}" />
                            @else
                                @if ($isSharedVariable)
                                    <x-forms.checkbox disabled id="is_multiline" label="{{ __('env_var.multiline_label') }}" />
                                @else
                                    @if (!$env->is_nixpacks)
                                        <x-forms.checkbox disabled id="is_buildtime"
                                            helper="{{ __('env_var.available_at_buildtime_helper') }}"
                                            label="{{ __('env_var.available_at_buildtime') }}" />
                                    @endif
                                    <x-forms.checkbox disabled id="is_runtime"
                                        helper="{{ __('env_var.available_at_runtime_helper') }}"
                                        label="{{ __('env_var.available_at_runtime') }}" />
                                    <x-forms.checkbox disabled id="is_multiline" label="{{ __('env_var.multiline_label') }}" />
                                    @if ($is_multiline === false)
                                        <x-forms.checkbox disabled id="is_literal"
                                            helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                                            label="{{ __('env_var.is_literal_label') }}" />
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
