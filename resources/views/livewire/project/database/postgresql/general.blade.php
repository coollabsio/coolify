<div>
    <dialog id="newInitScript" class="modal">
        <form method="dialog" class="flex flex-col gap-2 rounded-sm modal-box" wire:submit='save_new_init_script'>
            <h3 class="text-lg font-bold">{{ __('database.add_init_script') }}</h3>
            <x-forms.input placeholder="create_test_db.sql" id="new_filename" label="{{ __('database.filename') }}" required />
            <x-forms.textarea placeholder="CREATE DATABASE test;" id="new_content" label="{{ __('database.content') }}" required />
            <x-forms.button onclick="newInitScript.close()" type="submit">
                {{ __('button.save') }}
            </x-forms.button>
        </form>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>

    <form wire:submit="submit" class="flex flex-col gap-2">
        <div class="flex items-center gap-2">
            <h2>{{ __('database.general') }}</h2>
            <x-forms.button type="submit" canGate="update" :canResource="$database">
                {{ __('button.save') }}
            </x-forms.button>
        </div>
        <div class="flex flex-wrap gap-2 sm:flex-nowrap">
            <x-forms.input label="{{ __('database.name') }}" id="name" canGate="update" :canResource="$database" />
            <x-forms.input label="{{ __('database.description') }}" id="description" canGate="update" :canResource="$database" />
            <x-forms.input label="{{ __('application.image') }}" id="image" required canGate="update" :canResource="$database"
                helper="{{ __('database.image_helper_postgres') }}" />
        </div>
        <div class="pt-2 dark:text-warning">{{ __('database.sync_warning') }}
        </div>
        @if ($database->started_at)
            <div class="flex xl:flex-row flex-col gap-2">
                <x-forms.input label="{{ __('database.username') }}" id="postgresUser" placeholder="{{ __('database.username_placeholder') }}" canGate="update"
                    :canResource="$database"
                    helper="{{ __('database.username_helper') }}" />
                <x-forms.input label="{{ __('database.password') }}" id="postgresPassword" type="password" required canGate="update"
                    :canResource="$database"
                    helper="{{ __('database.password_helper') }}" />
                <x-forms.input label="{{ __('database.initial_database') }}" id="postgresDb"
                    placeholder="{{ __('database.initial_database_placeholder') }}" readonly
                    helper="{{ __('database.initial_database_readonly_helper') }}" />
            </div>
        @else
            <div class="flex xl:flex-row flex-col gap-2 pb-2">
                <x-forms.input label="{{ __('database.username') }}" id="postgresUser" placeholder="{{ __('database.username_placeholder') }}" canGate="update"
                    :canResource="$database" />
                <x-forms.input label="{{ __('database.password') }}" id="postgresPassword" type="password" required canGate="update"
                    :canResource="$database" />
                <x-forms.input label="{{ __('database.initial_database') }}" id="postgresDb"
                    placeholder="{{ __('database.initial_database_placeholder') }}" canGate="update" :canResource="$database" />
            </div>
        @endif
        <div class="flex gap-2">
            <x-forms.input label="{{ __('database.initial_database_args') }}" canGate="update" :canResource="$database" id="postgresInitdbArgs"
                placeholder="{{ __('database.initial_database_args_placeholder') }}" />
            <x-forms.input label="{{ __('database.host_auth_method') }}" canGate="update" :canResource="$database" id="postgresHostAuthMethod"
                placeholder="{{ __('database.host_auth_method_placeholder') }}" />
        </div>
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

            <x-forms.input label="{{ __('database.postgres_url_internal') }}"
                helper="{{ __('database.url_helper') }}"
                type="password" readonly wire:model="db_url" />
            @if ($db_url_public)
                <x-forms.input label="{{ __('database.postgres_url_public') }}"
                    helper="{{ __('database.url_helper') }}"
                    type="password" readonly wire:model="db_url_public" />
            @endif
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex items-center gap-2 py-2">
                <h3>{{ __('database.ssl_configuration') }}</h3>
                @if ($enableSsl && $certificateValidUntil)
                    <x-modal-confirmation title="{{ __('database.regenerate_ssl_certs') }}" buttonTitle="{{ __('database.regenerate_ssl_certs') }}"
                        :actions="[
                            __('database.regenerate_ssl_action_1'),
                            __('database.regenerate_ssl_action_2'),
                        ]" submitAction="regenerateSslCertificate" :confirmWithText="false"
                        :confirmWithPassword="false" />
                @endif
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
                <div class="w-64" wire:key='enable_ssl'>
                    @if ($database->isExited())
                        <x-forms.checkbox id="enableSsl" label="{{ __('database.enable_ssl') }}" wire:model.live="enableSsl"
                            instantSave="instantSaveSSL" canGate="update" :canResource="$database" />
                    @else
                        <x-forms.checkbox id="enableSsl" label="{{ __('database.enable_ssl') }}" wire:model.live="enableSsl"
                            instantSave="instantSaveSSL" disabled
                            helper="{{ __('database.db_stopped_to_change') }}" />
                    @endif
                </div>
                @if ($enableSsl)
                    <div class="mx-2">
                        @if ($database->isExited())
                            <x-forms.select id="sslMode" label="{{ __('database.ssl_mode') }}" wire:model.live="sslMode"
                                instantSave="instantSaveSSL"
                                helper="{{ __('database.ssl_mode_helper') }}" canGate="update"
                                :canResource="$database">
                                <option value="allow" title="{{ __('database.ssl_mode_allow_title') }}">{{ __('database.ssl_mode_allow') }}</option>
                                <option value="prefer" title="{{ __('database.ssl_mode_prefer_title') }}">{{ __('database.ssl_mode_prefer') }}</option>
                                <option value="require" title="{{ __('database.ssl_mode_require_title') }}">{{ __('database.ssl_mode_require') }}</option>
                                <option value="verify-ca" title="{{ __('database.ssl_mode_verify_ca_title') }}">{{ __('database.ssl_mode_verify_ca') }}</option>
                                <option value="verify-full" title="{{ __('database.ssl_mode_verify_full_title') }}">{{ __('database.ssl_mode_verify_full') }}
                                </option>
                            </x-forms.select>
                        @else
                            <x-forms.select id="sslMode" label="{{ __('database.ssl_mode') }}" instantSave="instantSaveSSL" disabled
                                helper="{{ __('database.db_stopped_to_change') }}">
                                <option value="allow" title="{{ __('database.ssl_mode_allow_title') }}">{{ __('database.ssl_mode_allow') }}</option>
                                <option value="prefer" title="{{ __('database.ssl_mode_prefer_title') }}">{{ __('database.ssl_mode_prefer') }}</option>
                                <option value="require" title="{{ __('database.ssl_mode_require_title') }}">{{ __('database.ssl_mode_require') }}</option>
                                <option value="verify-ca" title="{{ __('database.ssl_mode_verify_ca_title') }}">{{ __('database.ssl_mode_verify_ca') }}</option>
                                <option value="verify-full" title="{{ __('database.ssl_mode_verify_full_title') }}">{{ __('database.ssl_mode_verify_full') }}
                                </option>
                            </x-forms.select>
                        @endif
                    </div>
                @endif

                <div class="flex flex-col gap-2">
                    <div class="flex items-center gap-2 py-2">
                        <h3>{{ __('database.proxy') }}</h3>
                        <x-loading wire:loading wire:target="instantSave" />
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
                    <div class="flex flex-col gap-2 w-64">
                        <x-forms.checkbox instantSave id="isPublic" label="{{ __('database.make_publicly_available') }}"
                            canGate="update" :canResource="$database" />
                    </div>
                    <x-forms.input placeholder="5432" disabled="{{ $isPublic }}" id="publicPort"
                        label="{{ __('database.public_port') }}" canGate="update" :canResource="$database" />
                </div>

                <div class="flex flex-col gap-2">
                    <x-forms.textarea label="{{ __('database.custom_postgresql_config') }}" rows="10" id="postgresConf"
                        canGate="update" :canResource="$database" />
                </div>
            </div>
        </div>
    </form>

    <div class="flex flex-col gap-4 pt-4">
        <h3>{{ __('database.advanced') }}</h3>
        <div class="flex flex-col">
            <x-forms.checkbox helper="{{ __('database.drain_logs_helper') }}"
                instantSave="instantSaveAdvanced" id="isLogDrainEnabled" label="{{ __('database.drain_logs') }}" canGate="update"
                :canResource="$database" />
        </div>

        <div class="pb-16">
            <div class="flex items-center gap-2 pb-2">

                <h3>{{ __('database.initialization_scripts') }}</h3>
                @can('update', $database)
                    <x-modal-input buttonTitle="+ Add" title="{{ __('database.new_init_script') }}">
                        <form class="flex flex-col w-full gap-2 rounded-sm" wire:submit='save_new_init_script'>
                            <x-forms.input placeholder="create_test_db.sql" id="new_filename" label="{{ __('database.filename') }}"
                                required />
                            <x-forms.textarea rows="20" placeholder="CREATE DATABASE test;" id="new_content"
                                label="{{ __('database.content') }}" required />
                            <x-forms.button type="submit">
                                {{ __('button.save') }}
                            </x-forms.button>
                        </form>
                    </x-modal-input>
                @endcan
            </div>
            <div class="flex flex-col gap-2">
                @forelse($initScripts ?? [] as $script)
                    <livewire:project.database.init-script :script="$script" :wire:key="$script['index']" />
                @empty
                    <div>{{ __('database.no_init_scripts') }}</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
