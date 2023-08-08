<div>
    <form wire:submit.prevent='createGitHubApp' class="flex flex-col">
        <h2>GitHub App</h2>
        <div class="flex gap-2">
            <x-forms.input id="name" label="Name" required/>
            <x-forms.input helper="If empty, your GitHub user will be used." id="organization" label="Organization"/>
        </div>
        <h3 class="py-4">Advanced</h3>
        <div class="flex gap-2">
            <x-forms.input id="html_url" label="HTML Url" required/>
            <x-forms.input id="api_url" label="API Url" required/>
        </div>
        <div class="flex gap-2">
            <x-forms.input id="custom_user" label="Custom Git User" required/>
            <x-forms.input id="custom_port" label="Custom Git Port" required/>
        </div>
        <x-forms.checkbox class="pt-2" id="is_system_wide" label="System Wide"/>
        <x-forms.button type="submit">
            Save New Source
        </x-forms.button>
    </form>
</div>
