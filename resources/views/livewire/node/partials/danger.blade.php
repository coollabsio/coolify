@can('delete', $node)
    <x-application.settings-section id="node-danger-section" title="Danger zone"
        helper="Permanently remove this server from Coolify and revoke its Sentinel token.">
        <x-danger-zone title="This action cannot be undone">
            <p>
                Only servers that are not in a cluster and run no applications can be deleted.
                Sentinel stays installed on the host but can no longer connect to Coolify.
            </p>
            @if ($volumeData['volumes'] + $volumeData['copies'] > 0)
                <x-callout type="warning" title="This server still holds volume data">
                    {{ $volumeData['volumes'] }} {{ str('volume')->plural($volumeData['volumes']) }} and
                    {{ $volumeData['copies'] }} volume {{ str('copy')->plural($volumeData['copies']) }} are on this server.
                    Move or delete them first. If the server is gone for good, use the forced delete:
                    the data then stays on the server, and Coolify cannot remove it.
                </x-callout>
            @endif
            <p>Type the server name in the confirmation dialog to continue.</p>
            <x-slot:action>
                <div class="flex flex-wrap gap-2">
                    <x-modal-confirmation title="Confirm Server Deletion?" isErrorButton buttonTitle="Delete server"
                        submitAction="deleteNode"
                        :actions="['This server will be permanently deleted from Coolify.', 'Its Sentinel token is revoked.']"
                        confirmationText="{{ $node->name }}"
                        confirmationLabel="Please confirm by entering the Server Name below"
                        shortConfirmationLabel="Server Name" />
                    @if ($volumeData['volumes'] + $volumeData['copies'] > 0)
                        <x-modal-confirmation title="Force Server Deletion?" isErrorButton buttonTitle="Force delete server"
                            submitAction="forceDeleteNode"
                            :actions="[
                                'This server will be permanently deleted from Coolify.',
                                'Its Sentinel token is revoked.',
                                $volumeData['volumes'].' '.str('volume')->plural($volumeData['volumes']).' and '.$volumeData['copies'].' volume '.str('copy')->plural($volumeData['copies']).' stay on the server. Coolify cannot remove them after the delete.',
                            ]"
                            confirmationText="{{ $node->name }}"
                            confirmationLabel="Please confirm by entering the Server Name below"
                            shortConfirmationLabel="Server Name" />
                    @endif
                </div>
            </x-slot:action>
        </x-danger-zone>
    </x-application.settings-section>
@endcan
