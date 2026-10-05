@can('delete', $cluster)
    <x-application.settings-section id="node-cluster-danger-section" title="Delete cluster"
        helper="Permanently remove this cluster and its private network from Coolify.">
        <x-danger-zone title="This action cannot be undone">
            <p>
                Every server is removed from the cluster and its private network configuration is cleaned up.
                The servers stay connected to Coolify and can join another cluster.
                Remove all applications from this cluster first.
            </p>
            <p>Type the cluster name in the confirmation dialog to continue.</p>
            <x-slot:action>
                <x-modal-confirmation title="Confirm Cluster Deletion?" isErrorButton buttonTitle="Delete cluster"
                    submitAction="deleteCluster" :actions="['This cluster will be permanently deleted from Coolify.']"
                    confirmationText="{{ $cluster->name }}"
                    confirmationLabel="Please confirm by entering the Cluster Name below"
                    shortConfirmationLabel="Cluster Name" />
            </x-slot:action>
        </x-danger-zone>
    </x-application.settings-section>
@else
    <x-empty size="sm" title="No access" description="You do not have permission to delete this cluster."
        icon-name="shield-alert" />
@endcan
