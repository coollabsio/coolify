<div>
    <x-modal yesOrNo modalId="{{ $modalId }}" modalTitle="Delete Environment Variable">
        <x-slot:modalBody>
            <p>Are you sure you want to delete this environment variable <span
                    class="font-bold text-warning">({{ $env->key }})</span>?</p>
        </x-slot:modalBody>
    </x-modal>
    <form wire:submit.prevent='submit' class="flex flex-col items-center gap-2 xl:flex-row">
        @if ($isDisabled)
            <x-forms.input disabled id="env.key" />
            <x-forms.input disabled type="password" id="env.value" />
            @if ($type !== 'service')
                <x-forms.checkbox instantSave id="env.is_build_time" label="Build Variable?" />
            @endif
        @else
            <x-forms.input id="env.key" />
            <x-forms.input type="password" id="env.value" />
            @if ($type !== 'service')
                <x-forms.checkbox instantSave id="env.is_build_time" label="Build Variable?" />
            @endif
        @endif
        <div class="flex gap-2">
            @if ($isDisabled)
                <x-forms.button disabled type="submit">
                    Update
                </x-forms.button>
                <x-forms.button disabled isError isModal modalId="{{ $modalId }}">
                    Delete
                </x-forms.button>
            @else
                <x-forms.button type="submit">
                    Update
                </x-forms.button>
                <x-forms.button isError isModal modalId="{{ $modalId }}">
                    Delete
                </x-forms.button>
            @endif

        </div>
    </form>
</div>
