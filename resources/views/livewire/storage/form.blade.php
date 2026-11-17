<form wire:submit="submit" class="application-settings-form">
    <x-unsaved-bar action="submit" />

    <x-application.settings-section title="General"
        description="{{ $storage->isS3() ? 'S3-compatible destination' : $storage->type->label().' destination' }} used by database and volume backups.">
        <x-slot:actions>
            @can('validateConnection', $storage)
                <x-forms.button type="button" wire:click="testConnection">
                    <x-reicon name="check-circle" class="size-3.5" />
                    Validate connection
                </x-forms.button>
            @endcan
        </x-slot:actions>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input canGate="update" :canResource="$storage" label="Name" id="name" />
            <x-forms.input canGate="update" :canResource="$storage" label="Description" id="description" />
            <x-forms.input label="Type" :value="$storage->type->label()" disabled
                helper="The type cannot change after creation. Create a new storage for another type." />
            <div></div>
            @if ($storage->isS3())
            <div class="lg:col-span-2">
                @can('update', $storage)
                    {{-- Sends the endpoint right away, so the URL style below follows the provider. --}}
                    <div x-on:change="$wire.$commit()">
                        <x-forms.domain-input id="endpointParts" errorId="endpoint" host-label="Host"
                            host-placeholder="minio.internal or 192.168.1.50" />
                    </div>
                @else
                    <x-forms.input label="Endpoint" :value="$endpoint" disabled />
                @endcan
            </div>
            <x-forms.input canGate="update" :canResource="$storage" required label="Bucket" id="bucket" />
            <x-forms.input canGate="update" :canResource="$storage" required label="Region" id="region" />
            @if ($isPasswordHiddenForMember)
                <x-forms.input label="Access key" disabled value="Hidden (only admins can view)" />
                <x-forms.input label="Secret key" disabled value="Hidden (only admins can view)" />
            @else
                <x-forms.input canGate="update" :canResource="$storage" required type="password"
                    label="Access key" id="key" />
                <x-forms.input canGate="update" :canResource="$storage" type="password" label="Secret key"
                    id="secret" :required="! $hasSavedSecret"
                    :placeholder="$hasSavedSecret ? 'Saved. Leave empty to keep it.' : ''" />
            @endif
            <x-forms.checkbox canGate="update" :canResource="$storage" id="usePathStyleEndpoint"
                label="Use path-style URLs"
                helper="Turn this off for providers that only accept virtual-hosted-style URLs (bucket.endpoint). Coolify turns it off for Alibaba Cloud OSS, Tencent Cloud COS, and Volcengine TOS, and when the provider rejects path-style URLs." />
            <x-forms.checkbox canGate="update" :canResource="$storage" id="skipTlsVerification"
                label="Skip TLS certificate verification"
                helper="Turn this on only for an endpoint with a self-signed or private CA certificate. Coolify and the backup helper then accept any certificate, so an attacker on the network path can read or change your backups. Browsers that download files from this storage must still trust the certificate." />
            @else
                <div class="lg:col-span-2">
                    @if ($storage->type === App\Enums\BackupStorageType::Webdav)
                        @can('update', $storage)
                            <x-forms.domain-input id="endpointParts" errorId="endpoint" host-label="Host"
                                host-placeholder="cloud.example.com" port-placeholder="443"
                    path-placeholder="/remote.php/dav/files/USER/backups"
                    path-helper="The path of the backup directory on the WebDAV server, for example /remote.php/dav/files/USER/backups for Nextcloud." />
                        @else
                            <x-forms.input label="URL" :value="$endpoint" disabled />
                        @endcan
                    @else
                        @include('livewire.storage.server-endpoint-fields', [
                            'defaultPort' => $storage->type->defaultPort(),
                            'disabled' => auth()->user()?->cannot('update', $storage),
                        ])
                    @endif
                </div>
                @if ($isPasswordHiddenForMember)
                    <x-forms.input label="Username" disabled value="Hidden (only admins can view)" />
                    <x-forms.input label="Password" disabled value="Hidden (only admins can view)" />
                @elseif ($storage->type === App\Enums\BackupStorageType::Sftp)
                    <x-forms.input canGate="update" :canResource="$storage" required label="Username" id="key"
                        autocomplete="off" />
                    <div class="lg:col-span-2">
                        @include('livewire.storage.sftp-login-fields', [
                            'disabled' => auth()->user()?->cannot('update', $storage),
                        ])
                    </div>
                @else
                    <x-forms.input canGate="update" :canResource="$storage" required label="Username" id="key"
                        autocomplete="off" />
                    <x-forms.input canGate="update" :canResource="$storage" type="password" label="Password"
                        id="secret" :required="! $hasSavedSecret"
                        :placeholder="$hasSavedSecret ? 'Saved. Leave empty to keep it.' : ''" />
                @endif
                @if ($storage->type === App\Enums\BackupStorageType::Sftp)
                    <div class="flex items-end gap-2 lg:col-span-2">
                        <x-forms.input label="Host key fingerprint" disabled
                            :value="$storage->sftpHostKeyFingerprint() ?? 'Not saved yet. Validate the connection to save it.'"
                            helper="Coolify saves the host key of the server when you validate the connection, and refuses the server when the key changes. Backups do not save a new key. Reset it only when you know why the key changed." />
                        @can('update', $storage)
                            @if ($storage->sftp_host_key)
                                <x-forms.button type="button" wire:click="resetHostKey">Reset host key</x-forms.button>
                            @endif
                        @endcan
                    </div>
                @endif
            @endif
        </div>
    </x-application.settings-section>
</form>
