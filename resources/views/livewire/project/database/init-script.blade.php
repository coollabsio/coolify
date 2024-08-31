<form wire:submit="submit">
    <div class="flex items-end gap-2">
        <x-forms.input id="filename" label="Filename" />
        <x-forms.button type="submit">Save</x-forms.button>
        <x-modal-confirmation 
        isErrorButton
        title="Confirm init-script deletion?"
        buttonTitle="Delete"
        submitAction="delete"
        :actions="[
            'The init-script of this database will be permanently deleted.'
        ]"
        confirmationText="{{ $filename }}"
        confirmationLabel="Please confirm the execution of the actions by entering the init-script name below"
        shortConfirmationLabel="Init-script Name"
        :confirmWithPassword=false
        step2ButtonText="Permanently Delete Init-script"
        />
    </div>
    <x-forms.textarea id="content" label="Content" />
</form>
