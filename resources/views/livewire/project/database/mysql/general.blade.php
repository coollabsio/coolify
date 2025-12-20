<div>
    <form wire:submit="submit" class="flex flex-col gap-2">
        <div class="flex items-center gap-2">
            <h2>{{ __('database.general') }}</h2>
            <x-forms.button type="submit">
                {{ __('button.save') }}
            </x-forms.button>
        </div>
        <div class="flex gap-2">
            <x-forms.input label="{{ __('database.name') }}" id="name" canGate="update" :canResource="$database" />
            <x-forms.input label="{{ __('database.description') }}" id="description" canGate="update" :canResource="$database" />
            <x-forms.input label="{{ __('application.image') }}" id="image" required
                helper="{{ __('database.image_helper_mysql') }}" canGate="update" :canResource="$database" />
        </div>
        <div class="pt-2 dark:text-warning">{{ __('database.sync_warning') }}
        </div>
        @if ($database->started_at)
            <div class="flex xl:flex-row flex-col gap-2">
                <x-forms.input label="{{ __('database.root_password') }}" id="mysqlRootPassword" type="password" required
                    helper="{{ __('database.root_password_helper') }}" canGate="update" :canResource="$database" />
                <x-forms.input label="{{ __('database.normal_user') }}" id="mysqlUser" required
                    helper="{{ __('database.normal_user_helper') }}" canGate="update" :canResource="$database" />
                <x-forms.input label="{{ __('database.normal_user_password') }}" id="mysqlPassword" type="password" required
                    helper="{{ __('database.normal_user_password_helper') }}" canGate="update" :canResource="$database" />
            </div>
            <div class="flex flex-col gap-2">
                <x-forms.input label="{{ __('database.initial_database') }}" id="mysqlDatabase"
                    placeholder="{{ __('database.initial_database_placeholder') }}" readonly
                    helper="{{ __('database.initial_database_readonly_helper') }}" canGate="update" :canResource="$database" />
            </div>
        @else
            <div class="flex xl:flex-row flex-col gap-4 pb-2">
                <x-forms.input label="{{ __('database.root_password') }}" id="mysqlRootPassword" type="password"
                    helper="{{ __('database.initial_database_readonly_helper') }}" canGate="update" :canResource="$database" />
                <x-forms.input label="{{ __('database.normal_user') }}" id="mysqlUser" required
                    helper="{{ __('database.initial_database_readonly_helper') }}" canGate="update" :canResource="$database" />
                <x-forms.input label="{{ __('database.normal_user_password') }}" id="mysqlPassword" type="password" required
                    helper="{{ __('database.initial_database_readonly_helper') }}" canGate="update" :canResource="$database" />
            </div>
            <div class="flex flex-col gap-2">
                <x-forms.input label="{{ __('database.initial_database') }}" id="mysqlDatabase"
                    placeholder="{{ __('database.initial_database_placeholder') }}"
                    helper="{{ __('database.initial_database_readonly_helper') }}" canGate="update" :canResource="$database" />
            </div>
        @endif
        <div class="pt-2">
            <x-forms.input
                helper="{{ __('application.custom_docker_options_helper') }}"
                placeholder="{{ __('application.custom_docker_options_placeholder') }}"
                id="customDockerRunOptions" label="{{ __('application.custom_docker_options') }}" canGate="update" :canResource="$database" />
        </div>
        <div class="flex flex-col gap-2">
            <h3 class="py-2">{{ __('database.network') }}</h3>
            <div class="flex items-end gap-2">
                <x-forms.input placeholder="{{ __('database.ports_mappings_placeholder_db') }}" id="portsMappings" label="{{ __('application.ports_mappings') }}"
                    helper="{{ __('database.ports_mappings_helper_db') }}" canGate="update" :canResource="$database" />
            </div>
            <x-forms.input label="{{ __('database.mysql_url_internal') }}"
                helper="{{ __('database.url_helper') }}"
                type="password" readonly wire:model="db_url" />
            @if ($db_url_public)
                <x-forms.input label="{{ __('database.mysql_url_public') }}"
                    helper="{{ __('database.url_helper') }}"
                    type="password" readonly wire:model="db_url_public" />
            @endif
        </div>

        <div class="flex flex-col gap-2">
            <div class="flex items-center justify-between py-2">
                <div class="flex items-center justify-between w-full">
                    <h3>{{ __('database.ssl_configuration') }}</h3>
                    @if ($enableSsl && $certificateValidUntil)
                        <x-modal-confirmation title="{{ __('database.regenerate_ssl_certs') }}"
                            buttonTitle="{{ __('database.regenerate_ssl_certs') }}" :actions="[
                                __('database.regenerate_ssl_action_1'),
                                __('database.regenerate_ssl_action_2'),
                            ]"
                            submitAction="regenerateSslCertificate" :confirmWithText="false" :confirmWithPassword="false" />
                    @endif
                </div>
            </div>
            @if ($enableSsl && $certificateValidUntil)
                <span class="text-sm">{{ __('database.valid_until') }}
                    @if (now()->gt($certificateValidUntil))
                        <span class="text-red-500">{{ $certificateValidUntil->format('d.m.Y H:i:s') }} - {{ __('database.expired') }}</span>
                    @elseif(now()->addDays(30)->gt($certificateValidUntil))
                        <span class="text-red-500">{{ $certificateValidUntil->format('d.m.Y H:i:s') }} - {{ __('database.expiring_soon') }}</span>
                    @else
                        <span>{{ $certificateValidUntil->format('d.m.Y H:i:s') }}</span>
                    @endif
                </span>
            @endif
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex flex-col gap-2">
                <div class="w-64">
                    @if (str($database->status)->contains('exited'))
                        <x-forms.checkbox id="enableSsl" label="{{ __('database.enable_ssl') }}"
                            wire:model.live="enableSsl" instantSave="instantSaveSSL" canGate="update" :canResource="$database" />
                    @else
                        <x-forms.checkbox id="enableSsl" label="{{ __('database.enable_ssl') }}"
                            wire:model.live="enableSsl" instantSave="instantSaveSSL" disabled
                            helper="{{ __('database.db_stopped_to_change') }}" />
                    @endif
                </div>
                @if ($enableSsl)
                    <div class="mx-2">
                        @if (str($database->status)->contains('exited'))
                            <x-forms.select id="sslMode" label="{{ __('database.ssl_mode') }}" wire:model.live="sslMode"
                                instantSave="instantSaveSSL"
                                helper="{{ __('database.ssl_mode_helper_mysql') }}" canGate="update" :canResource="$database">
                                <option value="PREFERRED" title="{{ __('database.ssl_mode_preferred_title') }}">{{ __('database.ssl_mode_preferred') }}</option>
                                <option value="REQUIRED" title="{{ __('database.ssl_mode_required_title') }}">{{ __('database.ssl_mode_required') }}</option>
                                <option value="VERIFY_CA" title="{{ __('database.ssl_mode_verify_ca_title') }}">{{ __('database.ssl_mode_verify_ca') }}</option>
                                <option value="VERIFY_IDENTITY" title="{{ __('database.ssl_mode_verify_identity_title') }}">{{ __('database.ssl_mode_verify_identity') }}
                                </option>
                            </x-forms.select>
                        @else
                            <x-forms.select id="sslMode" label="{{ __('database.ssl_mode') }}" instantSave="instantSaveSSL"
                                disabled helper="{{ __('database.db_stopped_to_change') }}">
                                <option value="PREFERRED" title="{{ __('database.ssl_mode_preferred_title') }}">{{ __('database.ssl_mode_preferred') }}</option>
                                <option value="REQUIRED" title="{{ __('database.ssl_mode_required_title') }}">{{ __('database.ssl_mode_required') }}</option>
                                <option value="VERIFY_CA" title="{{ __('database.ssl_mode_verify_ca_title') }}">{{ __('database.ssl_mode_verify_ca') }}</option>
                                <option value="VERIFY_IDENTITY" title="{{ __('database.ssl_mode_verify_identity_title') }}">{{ __('database.ssl_mode_verify_identity') }}
                                </option>
                            </x-forms.select>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <div>
            <div class="flex flex-col py-2 w-64">
                <div class="flex items-center gap-2 pb-2">
                    <div class="flex items-center">
                        <h3>{{ __('database.proxy') }}</h3>
                        <x-loading wire:loading wire:target="instantSave" />
                    </div>
                    @if (data_get($database, 'is_public'))
                        <x-slide-over fullScreen>
                            <x-slot:title>{{ __('database.proxy_logs') }}</x-slot:title>
                            <x-slot:content>
                                <livewire:project.shared.get-logs :server="$server" :resource="$database"
                                    container="{{ data_get($database, 'uuid') }}-proxy" :collapsible="false" lazy />
                            </x-slot:content>
                            <x-forms.button disabled="{{ !data_get($database, 'is_public') }}"
                                @click="slideOverOpen=true">{{ __('database.logs') }}</x-forms.button>
                        </x-slide-over>
                    @endif
                </div>
                <x-forms.checkbox instantSave id="isPublic" label="{{ __('database.make_publicly_available') }}" canGate="update" :canResource="$database" />
            </div>
            <x-forms.input placeholder="5432" disabled="{{ $isPublic }}"
                id="publicPort" label="{{ __('database.public_port') }}" canGate="update" :canResource="$database" />
        </div>
        <x-forms.textarea label="{{ __('database.custom_mysql_config') }}" rows="10" id="mysqlConf" canGate="update" :canResource="$database" />
        <h3 class="pt-4">{{ __('database.advanced') }}</h3>
        <div class="flex flex-col">
            <x-forms.checkbox helper="{{ __('database.drain_logs_helper') }}"
                instantSave="instantSaveAdvanced" id="isLogDrainEnabled" label="{{ __('database.drain_logs') }}" canGate="update" :canResource="$database" />
        </div>
    </form>
</div>
