<div>
    <x-modal yesOrNo modalId="deleteServer" modalTitle="Delete Server">
        <x-slot:modalBody>
            <p>This server will be deleted. It is not reversible. <br>Please think again..</p>
        </x-slot:modalBody>
    </x-modal>
    @if ($server->id !== 0)
        <h2 class="pt-4">Danger Zone</h2>
        <div class="">Woah. I hope you know what are you doing.</div>
        <h4 class="pt-4">Delete Server</h4>
        <div class="pb-4">This will remove this server from Coolify. Beware! There is no coming
            back!
        </div>
        @if ($server->hasDefinedResources())
            <div class="text-warning">Please delete all resources before deleting this server.</div>
        @else
            <x-forms.button isError isModal modalId="deleteServer">
                Delete
            </x-forms.button>
        @endif
    @endif
</div>
