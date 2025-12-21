<x-modal-confirmation title="{{ __('modal.confirm_project_deletion') }}" buttonTitle="{{ __('modal.delete_project') }}" isErrorButton submitAction="delete"
    :actions="[
        __('project.delete_project_warning_1'),
        __('project.delete_project_warning_2'),
    ]" confirmationLabel="{{ __('project.confirm_delete_label') }}"
    shortConfirmationLabel="{{ __('project.project_name') }}" confirmationText="{{ $projectName }}" :confirmWithPassword="false"
    step2ButtonText="{{ __('common.permanently_delete') }}" />
