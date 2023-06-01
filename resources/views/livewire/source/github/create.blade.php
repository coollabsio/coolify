<div>
    <form wire:submit.prevent='createGitHubApp'>
        <div class="flex items-start gap-2 pt-6">
            <h2 class="">General</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </div>
        <x-forms.input id="name" label="Name" required />
        <x-forms.input helper="If empty, your GitHub user will be used." id="organization" label="Organization" />
        <h3 class="pt-4">Advanced</h3>
        <x-forms.input id="html_url" label="HTML Url" required />
        <x-forms.input id="api_url" label="API Url" required />
        <x-forms.input id="custom_user" label="Custom Git User" required />
        <x-forms.input id="custom_port" label="Custom Git Port" required />
        <x-forms.checkbox class="pt-2" id="is_system_wide" label="System Wide" />
    </form>
</div>
