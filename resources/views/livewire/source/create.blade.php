<div>
    <form class="flex flex-col gap-2 w-96" wire:submit.prevent='createGitHubApp'>
        <x-inputs.input id="name" label="Name" required />
        <x-inputs.input id="html_url" label="HTML Url" required />
        <x-inputs.input id="api_url" label="API Url" required />
        <x-inputs.input id="organization" label="Organization" />
        <x-inputs.input id="custom_user" label="Custom Git User" required />
        <x-inputs.input id="custom_port" label="Custom Git Port" required />
        <x-inputs.input type="checkbox" id="is_system_wide" label="System Wide" />
        <x-inputs.button type="submit">
            Submit
        </x-inputs.button>
    </form>
</div>
