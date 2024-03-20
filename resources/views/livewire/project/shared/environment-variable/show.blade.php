<div>
    <form wire:submit='submit'
        class="flex flex-col gap-2 p-4 m-2 border lg:items-center border-coolgray-300 lg:m-0 lg:p-0 lg:border-0 lg:flex-row">
        @if ($isLocked)
            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2">
                    <path d="M5 13a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6z" />
                    <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0-2 0m-3-5V7a4 4 0 1 1 8 0v4" />
                </g>
            </svg>
            <x-forms.input disabled id="env.key" />
        @else
            @if ($isDisabled)
                <x-forms.input disabled id="env.key" />
                <x-forms.input disabled type="password" id="env.value" />
                @if ($env->is_shared)
                    <x-forms.input disabled type="password" id="env.real_value" />
                @endif
                @if ($type !== 'service' && !$isSharedVariable)
                    <x-forms.checkbox instantSave id="env.is_build_time" label="Build Variable?" />
                @endif
            @else
                @if ($env->is_multiline)
                    <x-forms.input isMultiline="{{ $env->is_multiline }}" id="env.key" />
                    <x-forms.textarea type="password" id="env.value" />
                @else
                    <x-forms.input id="env.key" />
                    <x-forms.input type="password" id="env.value" />
                @endif
                @if ($env->is_shared)
                    <x-forms.input disabled type="password" id="env.real_value" />
                @else
                    <x-forms.checkbox instantSave id="env.is_multiline" label="Is Multiline?" />
                @endif
                @if ($type !== 'service' && !$isSharedVariable)
                    <x-forms.checkbox instantSave id="env.is_build_time" label="Build Variable?" />
                @endif
            @endif
        @endif
        <div class="flex gap-2">
            @if ($isLocked)
                <x-modal-confirmation isErrorButton buttonTitle="Delete">
                    You will delete environment variable <span
                        class="font-bold text-warning">{{ $env->key }}</span>.
                </x-modal-confirmation>
            @else
                @if ($isDisabled)
                    <x-forms.button disabled type="submit">
                        Update
                    </x-forms.button>
                    <x-forms.button wire:click='lock'>
                        Lock
                    </x-forms.button>
                    <x-modal-confirmation isErrorButton buttonTitle="Delete">
                        You will delete environment variable <span
                            class="font-bold text-warning">{{ $env->key }}</span>.
                    </x-modal-confirmation>
                @else
                    <x-forms.button type="submit">
                        Update
                    </x-forms.button>
                    <x-forms.button wire:click='lock'>
                        Lock
                    </x-forms.button>
                    <x-modal-confirmation isErrorButton buttonTitle="Delete">
                        You will delete environment variable <span
                            class="font-bold text-warning">{{ $env->key }}</span>.
                    </x-modal-confirmation>
                @endif
            @endif
        </div>
    </form>
</div>
