<form wire:submit="submit">
    <div class="flex items-end gap-2">
        <x-forms.input id="filename" label="{{ __('database.filename') }}" />
        <x-forms.button type="submit">{{ __('common.save') }}</x-forms.button>
        <x-modal-confirmation title="{{ __('modal.confirm_init_script_deletion') }}" buttonTitle="{{ __('modal.delete_init_script') }}" isErrorButton
            submitAction="delete" :actions="[
                'The init-script of this database will be permanently deleted form the database and the server.',
                'If you are actively using this init-script, it could cause errors on redeployment.',
            ]" confirmationText="{{ $filename }}"
            confirmationLabel="{{ __('modal.confirm_init_script_name_label') }}"
            shortConfirmationLabel="{{ __('database.init_script_name') }}" :confirmWithPassword=false step2ButtonText="Permanently Delete" />
    </div>
    <x-forms.textarea id="content" label="{{ __('database.content') }}" />
</form>
