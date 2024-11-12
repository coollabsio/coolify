<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Delete Server | Coolify
    </x-slot>
    <x-server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="danger" />
        <div class="w-full">
            @if ($server->id !== 0)
                <h2>Danger Zone</h2>
                <div class="">Woah. I hope you know what are you doing.</div>
                <h4 class="pt-4">Delete Server</h4>
                <div class="pb-4">This will remove this server from Coolify. Beware! There is no coming
                    back!
                </div>
                @if ($server->definedResources()->count() > 0)
                    <div class="pb-2 text-red-500">You need to delete all resources before deleting this server.</div>
                    <x-modal-confirmation title="Confirm Server Deletion?" isErrorButton buttonTitle="Delete"
                        submitAction="delete" :actions="['This server will be permanently deleted.']" confirmationText="{{ $server->name }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the Server Name below"
                        shortConfirmationLabel="Server Name" step3ButtonText="Permanently Delete" />
                @else
                    <x-modal-confirmation title="Confirm Server Deletion?" isErrorButton buttonTitle="Delete"
                        submitAction="delete" :actions="['This server will be permanently deleted.']" confirmationText="{{ $server->name }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the Server Name below"
                        shortConfirmationLabel="Server Name" step3ButtonText="Permanently Delete" />
                @endif
            @endif
        </div>
    </div>
</div>
