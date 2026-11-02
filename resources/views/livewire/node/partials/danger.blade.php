@can('delete', $node)
    <x-application.settings-section id="node-danger-section" title="Danger zone"
        helper="Permanently remove this server from Coolify and revoke its Sentinel token.">
        <x-danger-zone title="This action cannot be undone">
            <p>
                Only servers that are not in a cluster and run no applications can be deleted.
                Sentinel stays installed on the host but can no longer connect to Coolify.
            </p>
            <p>Type the server name in the confirmation dialog to continue.</p>
            <x-slot:action>
                <x-modal-confirmation title="Confirm Server Deletion?" isErrorButton buttonTitle="Delete server"
                    submitAction="deleteNode"
                    :actions="['This server will be permanently deleted from Coolify.', 'Its Sentinel token is revoked.']"
                    confirmationText="{{ $node->name }}"
                    confirmationLabel="Please confirm by entering the Server Name below"
                    shortConfirmationLabel="Server Name" />
            </x-slot:action>
        </x-danger-zone>
    </x-application.settings-section>
@endcan
