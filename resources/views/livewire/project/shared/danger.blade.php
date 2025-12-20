<div>
    <h2>{{ __('danger.title') }}</h2>
    <div class="">{{ __('danger.warning') }}</div>
    <h4 class="pt-4">{{ __('danger.delete_resource') }}</h4>
    <div class="pb-4">{{ __('danger.delete_description') }}
    </div>

    @if ($canDelete)
        <x-modal-confirmation title="{{ __('danger.confirm_delete_title') }}" buttonTitle="{{ __('button.delete') }}" isErrorButton submitAction="delete"
            buttonTitle="{{ __('button.delete') }}" :checkboxes="$checkboxes" :actions="[__('danger.delete_action')]" confirmationText="{{ $resourceName }}"
            confirmationLabel="{{ __('danger.confirm_delete_label') }}"
            shortConfirmationLabel="{{ __('danger.resource_name') }}" />
    @else
        <x-callout type="danger" title="{{ __('danger.insufficient_permissions') }}">
            {{ __('danger.no_permission_desc') }}
        </x-callout>
    @endif
</div>
