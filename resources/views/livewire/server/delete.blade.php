<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Delete Server | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="danger" />
        <div class="w-full">
            @if ($server->id !== 0)
                <h2>{{ __('server.danger_zone') }}</h2>
                <div class="">{{ __('server.danger_zone_warning') }}</div>
                <h4 class="pt-4">{{ __('server.delete_server') }}</h4>
                <div class="pb-4">{{ __('server.delete_server_desc') }}
                </div>
                @if ($server->definedResources()->count() > 0)
                    <div class="pb-2 text-red-500">{{ __('server.delete_all_resources_first') }}</div>
                @endif

                <x-modal-confirmation title="{{ __('modal.confirm_server_deletion') }}" isErrorButton buttonTitle="{{ __('modal.delete_server') }}"
                    submitAction="delete"
                    :actions="[__('server.server_will_be_deleted')]"
                    :checkboxes="$checkboxes"
                    confirmationText="{{ $server->name }}"
                    confirmationLabel="{{ __('server.confirm_server_name_label') }}"
                    shortConfirmationLabel="{{ __('server.server_name_short') }}" />
            @endif
        </div>
    </div>
</div>
