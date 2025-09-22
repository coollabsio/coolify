<div>
    <h2>Danger Zone</h2>
    <div class="">Woah. I hope you know what are you doing.</div>
    <h4 class="pt-4">Delete Resource</h4>
    <div class="pb-4">This will stop your containers, delete all related data, etc. Beware! There is no coming back!
    </div>

    @if ($canDelete)
        <x-modal-confirmation title="Confirm Resource Deletion?" buttonTitle="Delete" isErrorButton submitAction="delete"
            buttonTitle="Delete" :checkboxes="$checkboxes" :actions="['Permanently delete all containers of this resource.']" confirmationText="{{ $resourceName }}"
            confirmationLabel="Please confirm the execution of the actions by entering the Resource Name below"
            shortConfirmationLabel="Resource Name" />
    @else
        <div class="flex items-center gap-2 p-4 border border-red-500 rounded-lg bg-red-50 dark:bg-red-900/20">
            <svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd"
                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z"
                    clip-rule="evenodd"></path>
            </svg>
            <div>
                <div class="font-semibold text-red-700 dark:text-red-300">Insufficient Permissions</div>
                <div class="text-sm text-red-600 dark:text-red-400">You don't have permission to delete this resource.
                    Contact your team administrator for access.</div>
            </div>
        </div>
    @endif
</div>
