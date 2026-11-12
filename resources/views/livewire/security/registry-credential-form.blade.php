<div class="w-full">
    <form class="application-settings-form flex w-full flex-col gap-4" wire:submit="save">
        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input required id="name" label="Name" placeholder="GitHub Container Registry" />
            <x-forms.input required id="registry" label="Registry" placeholder="ghcr.io"
                helper="The registry host, with a port if it needs one. Use docker.io for Docker Hub." />
            <x-forms.input required id="username" label="Username" autocomplete="off" />
            <x-forms.input :required="$registry_credential_uuid === null" type="password" id="password"
                label="Password or token" autocomplete="new-password"
                placeholder="{{ $registry_credential_uuid === null ? 'Paste a password or an access token' : 'Leave blank to keep the current password' }}"
                helper="Use a token that can pull images, and push them if Git applications push their builds with it." />
        </div>
        <div class="flex justify-end">
            <x-forms.button type="submit" wire:target="save" isHighlighted>Save</x-forms.button>
        </div>
    </form>
</div>
