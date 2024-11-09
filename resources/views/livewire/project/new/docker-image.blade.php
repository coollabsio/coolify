<div>
    <h1>Create a new Application</h1>
    <div class="pb-4">You can deploy an existing Docker Image from any Registry.</div>
    <form wire:submit="submit">
        <div class="flex gap-2 pt-4 pb-1">
            <h2>Docker Image</h2>
            <x-forms.button type="submit">Save</x-forms.button>
        </div>
        <x-forms.input required id="dockerImage" label="Image" placeholder="nginx:latest" />

        <div class="pt-4 w-fit">
            <x-forms.checkbox wire:model.live="useCustomRegistry" id="useCustomRegistry"
                helper="If enabled, you can specify a custom registry URL, username, and token/password."
                label="Use Custom Registry Settings" />
        </div>

        @if ($useCustomRegistry)
            <h3 class="pt-4">Registry Authentication</h3>
            <div class="flex flex-col gap-4">
                <x-forms.input id="registryUrl" label="Registry URL" placeholder="registry.example.com"
                    helper="Leave empty for Docker Hub" />

                <x-forms.input id="registryUsername" label="Registry Username"
                    required="required_if:useCustomRegistry,true" placeholder="Username for private registry"
                    helper="Leave empty for public images or server credentials" />

                <x-forms.input type="password" id="registryToken" label="Registry Token/Password"
                    required="required_if:useCustomRegistry,true" placeholder="Token or password for private registry"
                    helper="Leave empty for public images or server credentials" />
            </div>
        @endif
    </form>
</div>
