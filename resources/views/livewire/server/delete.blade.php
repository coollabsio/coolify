<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Delete Server | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-8 grid w-full max-w-[1180px] min-w-0 gap-8 xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar :server="$server" activeMenu="danger" />

        <div class="application-settings-form w-full">
            @if ($server->id !== 0)
                <x-application.settings-section id="server-danger-section" title="Delete server"
                    helper="Permanently remove this server and its configuration from Coolify."
                    class="server-danger-section">
                    <x-slot:actions>
                        <x-status-badge status="Irreversible" type="error" />
                    </x-slot:actions>

                    <x-callout type="danger" title="This action cannot be undone">
                        The server will be removed from Coolify.
                        @if ($server->definedResources()->count() > 0)
                            It currently contains managed resources. Enable force deletion in the confirmation only
                            if those resources should also be removed.
                        @endif
                    </x-callout>

                    <div class="mt-4 flex items-center justify-between gap-4 rounded-lg bg-red-50 p-4 ring-1 ring-red-200 dark:bg-red-500/[0.08] dark:ring-red-500/20">
                        <div>
                            <p class="text-sm font-medium text-red-900 dark:text-red-200">Delete {{ $server->name }}</p>
                            <p class="mt-1 text-xs leading-5 text-red-700 dark:text-red-300/80">
                                Type the server name in the confirmation dialog to continue.
                            </p>
                        </div>
                        <x-modal-confirmation title="Confirm Server Deletion?" isErrorButton
                            buttonTitle="Delete server" submitAction="delete"
                            :actions="['This server will be permanently deleted from Coolify.']"
                            :checkboxes="$checkboxes" confirmationText="{{ $server->name }}"
                            confirmationLabel="Please confirm by entering the Server Name below"
                            shortConfirmationLabel="Server Name" />
                    </div>
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>
