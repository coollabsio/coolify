<div>
    <div class="pb-2 subtitle">
        <div>Save credentials for a Docker registry and reuse them across applications.</div>
        <div class="font-bold">Leave the registry URL empty for Docker Hub.</div>
    </div>
    <form class="flex flex-col gap-2" wire:submit='createRegistry'>
        <div class="flex gap-2">
            <x-forms.input id="name" label="Name" required />
            <x-forms.input id="registryUrl" label="Registry URL" placeholder="ghcr.io" />
        </div>
        <x-forms.input id="description" label="Description" />
        <div class="flex gap-2">
            <x-forms.input id="username" label="Username" />
            <x-forms.input id="password" type="password" label="Password or Token" autocomplete="new-password" />
        </div>
        <x-forms.button type="submit">
            Save
        </x-forms.button>
    </form>
</div>
