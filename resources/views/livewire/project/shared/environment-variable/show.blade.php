<div>
    <x-modal yesOrNo modalId="{{ $modalId }}" modalTitle="Delete Environment Variable">
        <x-slot:modalBody>
            <p>Are you sure you want to delete this environment variable <span
                    class="font-bold text-warning">({{ $env->key }})</span>?</p>
        </x-slot:modalBody>
    </x-modal>
    <form wire:submit.prevent='submit' class="flex flex-col items-center gap-2 xl:flex-row">
        <x-forms.input id="env.key"/>
        <x-forms.input type="password" id="env.value"/>
        @if (data_get($parameters, 'application_uuid'))
            <x-forms.checkbox disabled id="env.is_build_time" label="Build Variable?"/>
        @endif
        <div class="flex gap-2">
            <x-forms.button type="submit">
                Update
            </x-forms.button>
            <x-forms.button isError isModal modalId="{{ $modalId }}">
                Delete
            </x-forms.button>
        </div>
    </form>
</div>
