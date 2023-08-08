<dialog id="newVariable" class="modal">
    <form method="dialog" class="flex flex-col gap-2 rounded modal-box" wire:submit.prevent='submit'>
        <h3 class="text-lg font-bold">Add Environment Variable</h3>
        <x-forms.input placeholder="NODE_ENV" id="key" label="Name" required/>
        <x-forms.input placeholder="production" id="value" label="Value" required/>
        @if (data_get($parameters, 'application_uuid'))
            <x-forms.checkbox id="is_build_time" label="Build Variable?"/>
        @endif
        <x-forms.button onclick="newVariable.close()" type="submit">
            Save
        </x-forms.button>
    </form>
    <form method="dialog" class="modal-backdrop">
        <button>close</button>
    </form>
</dialog>
