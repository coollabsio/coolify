<div>
    <form wire:submit='submit'
        class="flex flex-col items-center gap-4 p-4 bg-white border lg:items-start dark:bg-base dark:border-coolgray-300">
        {{-- @if (!$env->isFoundInCompose && !$isSharedVariable)
            <div class="flex items-center justify-center gap-2 dark:text-warning text-coollabs"> <svg
                    class="hidden w-4 h-4 dark:text-warning lg:block" viewBox="0 0 256 256"
                    xmlns="http://www.w3.org/2000/svg">
                    <path fill="currentColor"
                        d="M240.26 186.1L152.81 34.23a28.74 28.74 0 0 0-49.62 0L15.74 186.1a27.45 27.45 0 0 0 0 27.71A28.31 28.31 0 0 0 40.55 228h174.9a28.31 28.31 0 0 0 24.79-14.19a27.45 27.45 0 0 0 .02-27.71m-20.8 15.7a4.46 4.46 0 0 1-4 2.2H40.55a4.46 4.46 0 0 1-4-2.2a3.56 3.56 0 0 1 0-3.73L124 46.2a4.77 4.77 0 0 1 8 0l87.44 151.87a3.56 3.56 0 0 1 .02 3.73M116 136v-32a12 12 0 0 1 24 0v32a12 12 0 0 1-24 0m28 40a16 16 0 1 1-16-16a16 16 0 0 1 16 16">
                    </path>
                </svg>This variable is not found in the compose file, so it won't be used.</div>
        @endif --}}
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
                            helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it form another value. In this case, you should set this to true."
                            label="Is Literal?" />
                    @else
                        @if ($isSharedVariable)
                            <x-forms.checkbox instantSave id="env.is_multiline" label="Is Multiline?" />
                        @else
                            <x-forms.checkbox instantSave id="env.is_build_time" label="Build Variable?" />
                            <x-forms.checkbox instantSave id="env.is_multiline" label="Is Multiline?" />
                            @if (!data_get($env, 'is_multiline'))
                                <x-forms.checkbox instantSave id="env.is_literal"
                                    helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it form another value. In this case, you should set this to true."
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
