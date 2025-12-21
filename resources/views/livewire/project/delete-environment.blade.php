<x-modal-confirmation title="{{ __('modal.confirm_environment_deletion') }}" buttonTitle="{{ __('modal.delete_environment') }}" isErrorButton
    submitAction="delete" :actions="[__('project.delete_environment_warning')]"
    confirmationLabel="{{ __('project.confirm_delete_environment_label') }}"
    shortConfirmationLabel="{{ __('project.environment_name') }}" confirmationText="{{ $environmentName }}" :confirmWithPassword="false"
    step2ButtonText="{{ __('common.permanently_delete') }}" />
