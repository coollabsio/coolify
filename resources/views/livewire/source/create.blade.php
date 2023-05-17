<div>
    <form wire:submit.prevent='createGitHubApp'>
        <x-inputs.button type="submit">
            Submit
        </x-inputs.button>
        <h3 class="pt-4">General</h3>
        <x-inputs.input id="name" label="Name" required />
        <x-inputs.input helper="If empty, your user will be used." id="organization" label="Organization" />
        <h3 class="pt-4">Advanced</h3>
        <x-inputs.input id="html_url" label="HTML Url" required />
        <x-inputs.input id="api_url" label="API Url" required />
        <x-inputs.input id="custom_user" label="Custom Git User" required />
        <x-inputs.input id="custom_port" label="Custom Git Port" required />
        <x-inputs.checkbox class="pt-2" id="is_system_wide" label="System Wide" />
    </form>
</div>
