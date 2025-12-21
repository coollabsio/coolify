<div>
    <form wire:submit='submit'>
        <div class="flex items-center gap-2 pb-4">
            @if ($database->human_name)
                <h2>{{ Str::headline($database->human_name) }}</h2>
            @else
                <h2>{{ Str::headline($database->name) }}</h2>
            @endif
            <x-forms.button canGate="update" :canResource="$database" type="submit">{{ __('common.save') }}</x-forms.button>
            @can('update', $database)
                <x-modal-confirmation wire:click="convertToApplication" title="{{ __('service.convert_to_application') }}"
                    buttonTitle="{{ __('service.convert_to_application') }}" submitAction="convertToApplication" :actions="[__('service.convert_to_application_warning')]"
                    confirmationText="{{ Str::headline($database->name) }}"
                    confirmationLabel="{{ __('service.confirm_service_database_deletion_label') }}"
                    shortConfirmationLabel="{{ __('service.service_database_name') }}" />
            @endcan
            @can('delete', $database)
                <x-modal-confirmation title="{{ __('modal.confirm_service_database_deletion') }}" buttonTitle="{{ __('modal.delete_service_database') }}" isErrorButton
                    submitAction="delete" :actions="[__('service.service_database_deletion_warning')]" confirmationText="{{ Str::headline($database->name) }}"
                    confirmationLabel="{{ __('service.confirm_service_database_deletion_label') }}"
                    shortConfirmationLabel="{{ __('service.service_database_name') }}" />
            @endcan
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex gap-2">
                <x-forms.input canGate="update" :canResource="$database" label="{{ __('input.name') }}" id="humanName" placeholder="{{ __('input.name') }}"></x-forms.input>
                <x-forms.input canGate="update" :canResource="$database" label="{{ __('input.description') }}" id="description"></x-forms.input>
                <x-forms.input canGate="update" :canResource="$database" required
                    helper="{!! __('service.image_helper') !!}"
                    label="{{ __('input.image') }}" id="image"></x-forms.input>
            </div>
            <div class="flex items-end gap-2">
                <x-forms.input canGate="update" :canResource="$database" placeholder="5432" disabled="{{ $database->is_public }}" id="publicPort"
                    label="{{ __('service.public_port') }}" />
                <x-forms.checkbox canGate="update" :canResource="$database" instantSave id="isPublic" label="{{ __('service.make_publicly_available') }}" />
            </div>
            @if ($db_url_public)
                <x-forms.input label="{{ __('service.database_public_url_label') }}"
                    helper="{{ __('service.database_public_url_helper') }}" type="password" readonly
                    wire:model="db_url_public" />
            @endif
        </div>
        <h3 class="pt-2">{{ __('menu.advanced') }}</h3>
        <div class="w-96">
            <x-forms.checkbox canGate="update" :canResource="$database" instantSave="instantSaveExclude" label="{{ __('service.exclude_from_status') }}"
                helper="{{ __('service.exclude_from_status_helper') }}"
                id="excludeFromStatus"></x-forms.checkbox>
            <x-forms.checkbox canGate="update" :canResource="$database" helper="{{ __('service.drain_logs_helper') }}"
                instantSave="instantSaveLogDrain" id="isLogDrainEnabled" label="{{ __('service.drain_logs') }}" />
        </div>
    </form>
</div>
