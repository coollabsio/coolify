<div>
    @if ($server->id !== 0)
        <h2 class="pt-4">Danger Zone</h2>
        <div class="">Woah. I hope you know what are you doing.</div>
        <h4 class="pt-4">Delete Server</h4>
        <div class="pb-4">This will remove this server from Coolify. Beware! There is no coming
            back!
        </div>
        @if ($server->definedResources()->count() > 0)
            <x-new-modal disabled isErrorButton buttonTitle="Delete">
                This server will be deleted. It is not reversible. <br>Please think again.
            </x-new-modal>
        @else
            <x-new-modal isErrorButton buttonTitle="Delete">
                This server will be deleted. It is not reversible. <br>Please think again.
            </x-new-modal>
        @endif
    @endif
</div>
