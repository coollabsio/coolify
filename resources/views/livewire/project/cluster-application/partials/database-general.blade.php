@php
    $databaseType = $workload->database_type;
    // Like v4 `started_at`: after the first deploy, the database may have created its data directory with these values.
    $wasDeployed = $workload->operations()->where('command_type', 'workload.deploy.v1')->exists();
    $canUpdate = auth()->user()->can('update', $workload);
@endphp

<form wire:submit="saveDatabase" class="flex flex-col gap-6">
    @can('update', $workload)
        <x-unsaved-bar action="saveDatabase" targets="name,description,databaseImage,databaseCredentials" />
    @endcan

    <x-application.settings-section id="database-details-section" title="Database details"
        description="Manage the identity, container image, and credentials of this {{ $databaseType->label() }} database.">
        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input label="Name" id="name" required canGate="update" :canResource="$workload" />
            <x-forms.input label="Description" id="description" canGate="update" :canResource="$workload" />
            <div class="lg:col-span-2">
                <x-forms.input label="Image" id="databaseImage" required canGate="update" :canResource="$workload"
                    helper="Use a published {{ $databaseType->label() }} image. Redeploy the database to use a new image." />
            </div>
        </div>
        <x-application.settings-subsection id="credentials-section" title="Credentials"
            helper="Coolify gives these values to the container as environment variables. Redeploy the database to apply a change.">
            @if ($wasDeployed)
                <x-callout type="warning" title="Keep credentials synchronized">
                    The database reads these values only when it creates its data directory. Changing values here does not
                    update {{ $databaseType->label() }}. Update {{ $databaseType->label() }} first, then synchronize the values
                    here and redeploy, so other applications keep working.
                </x-callout>
            @endif
            <div class="{{ $wasDeployed ? 'mt-4 ' : '' }}grid gap-4 lg:grid-cols-2">
                @foreach ($databaseType->credentials() as $credential)
                    @if (! $canUpdate)
                        <x-forms.input :label="$credential['label']" disabled value="Hidden (only admins can view)" />
                    @elseif (in_array($credential['key'], $lockedDatabaseCredentials, true))
                        <x-forms.input :label="$credential['label']" disabled value="Locked (shown once)"
                            helper="The {{ $credential['key'] }} variable is locked. Change it in Environment Variables." />
                    @else
                        <x-forms.input :label="$credential['label']" id="databaseCredentials.{{ $credential['key'] }}"
                            :type="$credential['default'] === null ? 'password' : 'text'" required
                            canGate="update" :canResource="$workload"
                            helper="Environment variable {{ $credential['key'] }}{{ $credential['file'] ? ', delivered as the secret file /run/secrets/'.$credential['key'] : '' }}." />
                    @endif
                @endforeach
            </div>
        </x-application.settings-subsection>
    </x-application.settings-section>
</form>

<x-application.settings-section id="access-section" title="Access"
    description="How applications in the cluster connect to this database.">
    <x-application.settings-subsection id="connection-section" title="Connection"
        helper="Only other workloads in the cluster can reach the internal hostname." first>
        <div class="space-y-5">
            @if (! $canUpdate)
                <x-forms.input :label="$databaseType->label().' URL (internal)'" disabled value="Hidden (only admins can view)" />
            @elseif ($lockedDatabaseCredentials !== [])
                <x-forms.input :label="$databaseType->label().' URL (internal)'" disabled value="Locked (shown once)"
                    helper="A credential variable is locked, so the URL that contains it is not shown." />
            @elseif ($databaseUrl)
                <x-forms.input :label="$databaseType->label().' URL (internal)'" type="password" readonly copyable
                    :value="$databaseUrl" helper="Built from the saved credentials. Redeploy after a change." />
            @else
                <x-forms.input :label="$databaseType->label().' URL (internal)'" readonly value="Pending"
                    helper="The internal hostname is assigned when the database is placed on a server." />
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                <x-forms.copy-input label="Internal hostname" :text="$internalHostname ?? 'Pending'" />
                <x-forms.copy-input label="Port" :text="(string) $databaseType->port()" />
            </div>
        </div>
    </x-application.settings-subsection>
</x-application.settings-section>
