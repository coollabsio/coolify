<div>
    <form wire:submit="submit" class="flex flex-col gap-2">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit" canGate="update" :canResource="$database">
                Save
            </x-forms.button>
        </div>
        <div class="flex gap-2">
            <x-forms.input label="Name" id="name" canGate="update" :canResource="$database" />
            <x-forms.input label="Description" id="description" canGate="update" :canResource="$database" />
            <x-forms.input label="Image" id="image" required canGate="update" :canResource="$database"
                helper="For all available images, check here:<br><br><a target='_blank' href='https://ghcr.io/tursodatabase/libsql-server'>https://ghcr.io/tursodatabase/libsql-server</a>" />
        </div>
        <div class="flex flex-col gap-2">
            <h3 class="py-2">Libsql</h3>

            @if (str($database->status)->contains('exited'))
                <x-forms.select wire:model.live="sqldNode" id="sqldNode" label="Node Type" canGate="update"
                    :canResource="$database">
                    <option value="primary" {{ $sqldNode === 'primary' ? 'selected' : '' }}>Primary</option>
                    <option value="replica" {{ $sqldNode === 'replica' ? 'selected' : '' }}>Replica</option>
                </x-forms.select>
            @else
                <x-forms.select wire:model.live="sqldNode" id="sqldNode" label="Node Type" disabled canGate="update"
                    :canResource="$database" helper="Database should be stopped to change this settings.">
                    <option value="primary" {{ $sqldNode === 'primary' ? 'selected' : '' }}>Primary</option>
                    <option value="replica" {{ $sqldNode === 'replica' ? 'selected' : '' }}>Replica</option>
                </x-forms.select>
            @endif

            @if ($sqldNode === 'replica')
                @if (str($database->status)->contains('exited'))
                    <x-forms.input id="sqldPrimaryUrl" label="Primary URL" placeholder="https://<host>:<port>" required
                        canGate="update" :canResource="$database"
                        helper="URL of the primary LibSQL server to replicate from" />
                @else
                    <x-forms.input id="sqldPrimaryUrl" label="Primary URL" placeholder="https://<host>:<port>" required
                        disabled canGate="update" :canResource="$database"
                        helper="Database should be stopped to change this settings." />
                @endif
            @endif
            <div class="flex gap-2">

                @if (str($database->status)->contains('exited'))
                    <x-forms.input id="sqldHttpAuthUser" label="HTTP Basic user" canGate="update" :canResource="$database" />
                @else
                    <x-forms.input id="sqldHttpAuthUser" label="HTTP Basic user" disabled canGate="update"
                        :canResource="$database" helper="Database should be stopped to change this settings." />
                @endif

                @if (str($database->status)->contains('exited'))
                    <x-forms.input type="password" id="sqldHttpAuthPassword" label="HTTP Basic password"
                        canGate="update" :canResource="$database" />
                @else
                    <x-forms.input type="password" id="sqldHttpAuthPassword" label="HTTP Basic password" disabled
                        canGate="update" :canResource="$database"
                        helper="Database should be stopped to change this settings." />
                @endif

                @if (str($database->status)->contains('exited'))
                    <x-forms.input id="sqldAuthJwtKey" label="HTTP JWT key" canGate="update" :canResource="$database" />
                @else
                    <x-forms.input id="sqldAuthJwtKey" label="HTTP JWT key" disabled canGate="update" :canResource="$database"
                        helper="Database should be stopped to change this settings." />
                @endif
            </div>
            <div class="flex gap-2">

                @if (str($database->status)->contains('exited'))
                    <x-forms.input id="sqldHttpPort" label="HTTP Listen Port" placeholder="8080" canGate="update"
                        :canResource="$database" helper="Port for HTTP connections (sets SQLD_HTTP_LISTEN_ADDR)" />
                @else
                    <x-forms.input id="sqldHttpPort" label="HTTP Listen Port" placeholder="8080" canGate="update"
                        disabled :canResource="$database" helper="Database should be stopped to change this settings." />
                @endif
                @if ($sqldNode === 'primary')

                    @if (str($database->status)->contains('exited'))
                        <x-forms.input id="sqldGrpcPort" label="gRPC Listen Port" placeholder="5001" canGate="update"
                            :canResource="$database"
                            helper="Port for gRPC connections (sets SQLD_GRPC_LISTEN_ADDR) - Only for primary nodes" />
                    @else
                        <x-forms.input id="sqldGrpcPort" label="gRPC Listen Port" placeholder="5001" canGate="update"
                            disabled :canResource="$database" helper="Database should be stopped to change this settings." />
                    @endif
                @endif
            </div>
            @if ($sqldNode === 'primary')
                <x-forms.input label="Libsql Replication URL (internal)"
                    helper="If you change the user/password/port, this could be different. This is with the default values."
                    type="password" readonly wire:model="dbReplicationUrl" canGate="update" :canResource="$database" />
            @endif
        </div>

        <div class="flex flex-col gap-2">
            <h3 class="py-2">Network</h3>
            <div class="flex items-end gap-2">
                <x-forms.input placeholder="3000:8080" id="portsMappings" label="Ports Mappings"
                    helper="A comma separated list of ports you would like to map to the host system.<br><span class='inline-block font-bold dark:text-warning'>Example</span>3000:5432,3002:5433"
                    canGate="update" :canResource="$database" />
            </div>
            <x-forms.input label="Libsql URL (internal)"
                helper="If you change the user/password/port, this could be different. This is with the default values."
                type="password" readonly wire:model="dbUrl" canGate="update" :canResource="$database" />
            @if ($dbUrlPublic)
                <x-forms.input label="Libsql URL (public)"
                    helper="If you change the user/password/port, this could be different. This is with the default values."
                    type="password" readonly wire:model="dbUrlPublic" canGate="update" :canResource="$database" />
            @else
                <x-forms.input label="Libsql URL (public)"
                    helper="If you change the user/password/port, this could be different. This is with the default values."
                    readonly value="Starting the database will generate this." canGate="update" :canResource="$database" />
            @endif
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex items-center justify-between py-2">
                <div class="flex items-center justify-between w-full">
                    <h3>SSL Configuration</h3>
                    @if ($database->enable_ssl && $certificateValidUntil)
                        <x-modal-confirmation title="Regenerate SSL Certificates"
                            buttonTitle="Regenerate SSL Certificates" :actions="[
                                'The SSL certificate of this database will be regenerated.',
                                'You must restart the database after regenerating the certificate to start using the new certificate.',
                            ]"
                            submitAction="regenerateSslCertificate" :confirmWithText="false" :confirmWithPassword="false" />
                    @endif
                </div>
            </div>
            @if ($database->enable_ssl && $certificateValidUntil)
                <span class="text-sm">Valid until:
                    @if (now()->gt($certificateValidUntil))
                        <span class="text-red-500">{{ $certificateValidUntil->format('d.m.Y H:i:s') }} - Expired</span>
                    @elseif(now()->addDays(30)->gt($certificateValidUntil))
                        <span class="text-red-500">{{ $certificateValidUntil->format('d.m.Y H:i:s') }} - Expiring
                            soon</span>
                    @else
                        <span>{{ $certificateValidUntil->format('d.m.Y H:i:s') }}</span>
                    @endif
                </span>
            @endif
            <div class="flex flex-col gap-2">
                <div class="w-64">
                    @if (str($database->status)->contains('exited'))
                        <x-forms.checkbox id="enable_ssl" label="Enable SSL" wire:model.live="enable_ssl"
                            instantSave="instantSaveSSL" canGate="update" :canResource="$database" />
                    @else
                        <x-forms.checkbox id="enable_ssl" label="Enable SSL" wire:model.live="enable_ssl"
                            instantSave="instantSaveSSL" disabled
                            helper="Database should be stopped to change this settings." canGate="update"
                            :canResource="$database" />
                    @endif
                </div>
            </div>

            <div>
                <div class="flex flex-col py-2 w-64">
                    <div class="flex items-center gap-2 pb-2">
                        <div class="flex items-center">
                            <h3>Proxy</h3>
                            <x-loading wire:loading wire:target="instantSave" />
                        </div>
                        @if ($isPublic)
                            <x-slide-over fullScreen>
                                <x-slot:title>Proxy Logs</x-slot:title>
                                <x-slot:content>
                                    <livewire:project.shared.get-logs :server="$server" :resource="$database"
                                        container="{{ data_get($database, 'uuid') }}-proxy" lazy />
                                </x-slot:content>
                                <x-forms.button disabled="{{ !$isPublic }}"
                                    @click="slideOverOpen=true">Logs</x-forms.button>
                            </x-slide-over>
                        @endif
                    </div>
                    <x-forms.checkbox instantSave id="isPublic" label="Make it publicly available" canGate="update"
                        :canResource="$database" />
                </div>
                <x-forms.input placeholder="5432" disabled="{{ $isPublic }}" id="publicPort" label="Public Port"
                    canGate="update" :canResource="$database" />
            </div>
    </form>

    <h3 class="pt-4">Advanced</h3>
    <div class="w-64">
        <x-forms.checkbox helper="Drain logs to your configured log drain endpoint in your Server settings."
            instantSave="instantSaveAdvanced" id="isLogDrainEnabled" label="Drain Logs" canGate="update"
            :canResource="$database" />
    </div>
</div>
