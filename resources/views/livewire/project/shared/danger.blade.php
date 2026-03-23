<div>
    <h2>Danger Zone</h2>
    <div class="">Woah. I hope you know what are you doing.</div>
    <h4 class="pt-4">Delete Resource</h4>
    <div class="pb-4">This will stop your containers, delete all related data, etc. Beware! There is no coming back!
    </div>

    @if ($canDelete)
        @if (! $queueWorkersAvailable)
            <x-callout type="warning" title="Queue Worker Not Running" class="mb-4">
                Destructive cleanup jobs require active queue workers. Start Horizon/queue workers first, then retry.
            </x-callout>
        @endif
        <x-modal-confirmation title="Confirm Resource Deletion?" buttonTitle="Delete" isErrorButton submitAction="delete"
            buttonTitle="Delete" :checkboxes="$checkboxes" :actions="['Permanently delete all containers of this resource.']" confirmationText="{{ $resourceName }}"
            confirmationLabel="Please confirm the execution of the actions by entering the Resource Name below" :disabled="!$queueWorkersAvailable"
            shortConfirmationLabel="Resource Name" />
    @else
        <x-callout type="danger" title="Insufficient Permissions">
            You don't have permission to delete this resource. Contact your team administrator for access.
        </x-callout>
    @endif
</div>
