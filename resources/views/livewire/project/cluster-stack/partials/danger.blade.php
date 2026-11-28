@can('delete', $stack)
    <x-application.settings-section title="Danger zone" helper="Destructive resource actions cannot be undone.">
        <x-danger-zone title="Delete stack">
            <p>
                Permanently delete <strong class="font-semibold text-black dark:text-fg">{{ $stack->name }}</strong>
                and every service of it: their containers, domains, and environment variables.
            </p>
            <x-slot:action>
                <x-modal-confirmation title="Delete stack?" buttonTitle="Delete stack" isErrorButton
                    submitAction="delete(true)"
                    :actions="['Permanently delete every service of this stack and remove its containers and volumes from every server.']"
                    confirmationText="{{ $stack->name }}"
                    confirmationLabel="Enter the stack name to confirm permanent deletion"
                    shortConfirmationLabel="Stack name" />
            </x-slot:action>
        </x-danger-zone>
    </x-application.settings-section>
@endcan
