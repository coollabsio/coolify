<form wire:submit="submit">
    <div class="flex items-end gap-2">
        <x-forms.input id="filename" label="Filename" />
        <x-forms.button type="submit">Save</x-forms.button>
        <x-modal-confirmation title="Confirm init-script deletion?" buttonTitle="Delete" isErrorButton
            submitAction="delete" :actions="[
                'The init-script of this database will be permanently deleted form the database and the server.',
                'If you are actively using this init-script, it could cause errors on redeployment.',
            ]" confirmationText="{{ $filename }}"
            confirmationLabel="Please confirm the execution of the actions by entering the init-script name below"
            shortConfirmationLabel="Init-script Name" :confirmWithPassword=false step2ButtonText="Permanently Delete" />
    </div>
    <x-forms.textarea id="content" label="Content" />
</form>
