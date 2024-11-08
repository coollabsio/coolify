<div>
    <h1>Create a new Application</h1>
    <div class="pb-4">You can deploy an existing Docker Image from any Registry.</div>
    <form wire:submit="submit">
        <div class="flex gap-2 pt-4 pb-1">
            <h2>Docker Image</h2>
            <x-forms.button type="submit">Save</x-forms.button>
        </div>
        <x-forms.input required id="dockerImage" label="Image" placeholder="nginx:latest" />

        <h3 class="pt-4">Registry Authentication</h3>
        <div class="flex flex-col gap-4">
            <x-forms.input id="registryUsername" required="registryToken" label="Registry Username"
                wire:model="registryUsername" placeholder="Username for private registry"
                helper="Leave empty for public images or server credentials" />

            <x-forms.input type="password" required="registryUsername" id="registryToken"
                label="Registry Token/Password" wire:model="registryToken"
                placeholder="Token or password for private registry"
                helper="Leave empty for public images or server credentials" />
        </div>
    </form>
</div>
