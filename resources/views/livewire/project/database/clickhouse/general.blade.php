<div>
    <form wire:submit="submit" class="flex flex-col gap-2">
        <div class="flex items-center gap-2">
            <h2>{{ __('database.general') }}</h2>
            <x-forms.button type="submit" canGate="update" :canResource="$database">
                {{ __('button.save') }}
            </x-forms.button>
        </div>
        <div class="flex gap-2">
            <x-forms.input label="{{ __('database.name') }}" id="name" canGate="update" :canResource="$database" />
            <x-forms.input label="{{ __('database.description') }}" id="description" canGate="update" :canResource="$database" />
            <x-forms.input label="{{ __('application.image') }}" id="image" required canGate="update" :canResource="$database"
                helper="{{ __('database.image_helper_clickhouse') }}" />
        </div>

        @if ($database->started_at)
            <div class="flex gap-2">
                <x-forms.input label="{{ __('database.initial_username') }}" id="clickhouseAdminUser" placeholder="{{ __('database.username_placeholder') }}"
                    readonly helper="{{ __('database.initial_database_readonly_helper') }}" canGate="update" :canResource="$database" />
                <x-forms.input label="{{ __('database.initial_password') }}" id="clickhouseAdminPassword" type="password" required readonly
                    helper="{{ __('database.initial_database_readonly_helper') }}" canGate="update" :canResource="$database" />
            </div>
        @else
            <div class=" dark:text-warning">{{ __('database.keydb_verify_values') }}
            </div>
            <div class="flex gap-2">
                <x-forms.input label="{{ __('database.username') }}" id="clickhouseAdminUser" required canGate="update" :canResource="$database" />
                <x-forms.input label="{{ __('database.password') }}" id="clickhouseAdminPassword" type="password" required canGate="update"
                    :canResource="$database" />
            </div>
        @endif
        <x-forms.input
            helper="{{ __('application.custom_docker_options_helper') }}"
            placeholder="{{ __('application.custom_docker_options_placeholder') }}"
            id="customDockerRunOptions" label="{{ __('application.custom_docker_options') }}" canGate="update" :canResource="$database" />
        <div class="flex flex-col gap-2">
            <h3 class="py-2">{{ __('database.network') }}</h3>
            <div class="flex items-end gap-2">
                <x-forms.input placeholder="{{ __('database.ports_mappings_placeholder_db') }}" id="portsMappings" label="{{ __('application.ports_mappings') }}"
                    helper="{{ __('database.ports_mappings_helper_db') }}"
                    canGate="update" :canResource="$database" />
            </div>
            <x-forms.input label="{{ __('database.clickhouse_url_internal') }}"
                helper="{{ __('database.url_helper') }}"
                type="password" readonly wire:model="dbUrl" canGate="update" :canResource="$database" />
            @if ($dbUrlPublic)
                <x-forms.input label="{{ __('database.clickhouse_url_public') }}"
                    helper="{{ __('database.url_helper') }}"
                    type="password" readonly wire:model="dbUrlPublic" canGate="update" :canResource="$database" />
            @else
                <x-forms.input label="{{ __('database.clickhouse_url_public') }}"
                    helper="{{ __('database.url_helper') }}"
                    readonly value="{{ __('database.keydb_url_generate_hint') }}" canGate="update" :canResource="$database" />
            @endif
        </div>
        <div>
            <div class="flex flex-col py-2 w-64">
                <div class="flex items-center gap-2 pb-2">
                    <div class="flex items-center">
                        <h3>{{ __('database.proxy') }}</h3>
                        <x-loading wire:loading wire:target="instantSave" />
                    </div>
                    @if ($isPublic)
                        <x-slide-over fullScreen>
                            <x-slot:title>{{ __('database.proxy_logs') }}</x-slot:title>
                            <x-slot:content>
                                <livewire:project.shared.get-logs :server="$server" :resource="$database"
                                    container="{{ data_get($database, 'uuid') }}-proxy" :collapsible="false" lazy />
                            </x-slot:content>
                            <x-forms.button disabled="{{ !$isPublic }}"
                                @click="slideOverOpen=true">{{ __('database.logs') }}</x-forms.button>
                        </x-slide-over>
                    @endif
                </div>
                <x-forms.checkbox instantSave id="isPublic" label="{{ __('database.make_publicly_available') }}"
                    canGate="update" :canResource="$database" />
            </div>
            <x-forms.input placeholder="5432" disabled="{{ $isPublic }}" id="publicPort" label="{{ __('database.public_port') }}"
                canGate="update" :canResource="$database" />
        </div>
    </form>
    <h3 class="pt-4">{{ __('database.advanced') }}</h3>
    <div class="w-64">
        <x-forms.checkbox helper="{{ __('database.drain_logs_helper') }}"
            instantSave="instantSaveAdvanced" id="isLogDrainEnabled" label="{{ __('database.drain_logs') }}" canGate="update"
            :canResource="$database" />
    </div>
</div>
