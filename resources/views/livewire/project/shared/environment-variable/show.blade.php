<div>
    <x-modal yesOrNo modalId="{{ $modalId }}" modalTitle="Delete Environment Variable">
        <x-slot:modalBody>
            <p>Are you sure you want to delete this environment variable <span
                    class="font-bold text-warning">({{ $env->key }})</span>?</p>
        </x-slot:modalBody>
    </x-modal>
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
                <x-forms.input id="env.key" />
                <x-forms.input type="password" id="env.value" />
                @if ($env->is_shared)
                    <x-forms.input disabled type="password" id="env.real_value" />
                @endif
                @if ($type !== 'service' && !$isSharedVariable)
                    <x-forms.checkbox instantSave id="env.is_build_time" label="Build Variable?" />
                @endif
            @endif
        @endif
        <div class="flex gap-2">
            @if ($isLocked)
                <x-forms.button isError isModal modalId="{{ $modalId }}">
                    Delete
                </x-forms.button>
            @else
                @if ($isDisabled)
                    <x-forms.button disabled type="submit">
                        Update
                    </x-forms.button>
                    <x-forms.button wire:click='lock'>
                        Lock
                    </x-forms.button>
                    <x-forms.button disabled isError isModal modalId="{{ $modalId }}">
                        Delete
                    </x-forms.button>
                @else
                    <x-forms.button type="submit">
                        Update
                    </x-forms.button>
                    <x-forms.button wire:click='lock'>
                        Lock
                    </x-forms.button>
                    <x-forms.button isError isModal modalId="{{ $modalId }}">
                        Delete
                    </x-forms.button>
                @endif
            @endif
        </div>
    </form>
</div>
