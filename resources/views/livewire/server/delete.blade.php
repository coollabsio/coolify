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
        @if ($server->definedResources()->count() > 0)
            <x-forms.button disabled isError isModal modalId="deleteServer">
                Delete
            </x-forms.button>
        @else
            <x-forms.button isError isModal modalId="deleteServer">
                Delete
            </x-forms.button>
        @endif
        @forelse ($server->definedResources() as $resource)
            @if ($loop->first)
            <h3 class="pt-4">Defined resources</h3>
                <div class="pt-4 text-warning">Please delete all resources before deleting this server.</div>
            @endif
            <div class="flex gap-2">
                <div class="w-64">{{ str($resource->type())->headline() }}</div>
                <a class="text-white underline" href="{{ $resource->link() }}">{{ $resource->name }}</a>
            </div>
        @empty
        @endforelse
    @else
    <div class="flex flex-col">
        @forelse ($server->definedResources() as $resource)
            @if ($loop->first)
                <h3 class="pt-4">Defined resources</h3>
            @endif
            <a class="flex gap-2 p-1 hover:bg-coolgray-100 hover:no-underline" href="{{ $resource->link() }}">
                <div class="w-64">{{ str($resource->type())->headline() }}</div>
                <div >{{ $resource->name }}</div>
            </a>
        @empty
        @endforelse
        </div>
    @endif
</div>
