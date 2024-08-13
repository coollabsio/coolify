<div>
    <form wire:submit='submit'
        class="flex flex-col items-center gap-4 p-4 bg-white border lg:items-start dark:bg-base dark:border-coolgray-300">
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
                <x-modal-confirmation isErrorButton buttonTitle="Delete">
                    You will delete environment variable <span
                        class="font-bold dark:text-warning text-coollabs">{{ $env->key }}</span>.
                </x-modal-confirmation>
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
                    <x-forms.checkbox instantSave id="env.is_build_time" label="Build Variable?" />
                @else
                    @if ($env->is_shared)
                        <x-forms.checkbox instantSave id="env.is_build_time" label="Build Variable?" />
                        <x-forms.checkbox instantSave id="env.is_literal"
                            helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
                            label="Is Literal?" />
                    @else
                        @if ($isSharedVariable)
                            <x-forms.checkbox instantSave id="env.is_multiline" label="Is Multiline?" />
                        @else
                            <x-forms.checkbox instantSave id="env.is_build_time" label="Build Variable?" />
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
                    <x-modal-confirmation isErrorButton buttonTitle="Delete">
                        You will delete environment variable <span
                            class="font-bold dark:text-warning">{{ $env->key }}</span>.
                    </x-modal-confirmation>
                @else
                    <x-forms.button type="submit">
                        Update
                    </x-forms.button>
                    <x-forms.button wire:click='lock'>
                        Lock
                    </x-forms.button>
                    <x-modal-confirmation buttonFullWidth isErrorButton buttonTitle="Delete">
                        You will delete environment variable <span
                            class="font-bold dark:text-warning">{{ $env->key }}</span>.
                    </x-modal-confirmation>
                @endif
            </div>
        @endif

    </form>
</div>
