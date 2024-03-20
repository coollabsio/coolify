<div>
    @if ($server->id !== 0)
        <h2 class="pt-4">Danger Zone</h2>
        <div class="">Woah. I hope you know what are you doing.</div>
        <h4 class="pt-4">Delete Server</h4>
        <div class="pb-4">This will remove this server from Coolify. Beware! There is no coming
            back!
        </div>
        @if ($server->definedResources()->count() > 0)
            <div class="pb-2 text-red-500">You need to delete all resources before deleting this server.</div>
            <x-modal-confirmation disabled isErrorButton buttonTitle="Delete">
                This server will be deleted. It is not reversible. <br>Please think again.
            </x-modal-confirmation>
        @else
            <x-modal-confirmation isErrorButton buttonTitle="Delete">
                This server will be deleted. It is not reversible. <br>Please think again.
            </x-modal-confirmation>
        @endif
    @endif
</div>
