<div x-data="{ deleteSource: false }">
    <x-naked-modal show="deleteSource" message='Are you sure you would like to delete this source?' />
    <form wire:submit.prevent='submit'>
        <x-inputs.input id="github_app.name" label="App Name" required />

        @if ($github_app->app_id)
            <x-inputs.input id="github_app.organization" label="Organization" disabled
                placeholder="Personal user if empty" />
        @else
            <x-inputs.input id="github_app.organization" label="Organization" placeholder="Personal user if empty" />
        @endif
        <x-inputs.input id="github_app.api_url" label="API Url" disabled />
        <x-inputs.input id="github_app.html_url" label="HTML Url" disabled />
        <x-inputs.input id="github_app.custom_user" label="User" required />
        <x-inputs.input type="number" id="github_app.custom_port" label="Port" required />

        @if ($github_app->app_id)
            <x-inputs.input type="number" id="github_app.app_id" label="App Id" disabled />
            <x-inputs.input type="number" id="github_app.installation_id" label="Installation Id" disabled />
            <x-inputs.input id="github_app.client_id" label="Client Id" type="password" disabled />
            <x-inputs.input id="github_app.client_secret" label="Client Secret" type="password" disabled />
            <x-inputs.input id="github_app.webhook_secret" label="Webhook Secret" type="password" disabled />
            <x-inputs.input noDirty type="checkbox" label="System Wide?" instantSave id="is_system_wide" />
            <div class="py-2">
                <x-inputs.button isBold type="submit">Save</x-inputs.button>
                <x-inputs.button isWarning x-on:click.prevent="deleteSource = true">
                    Delete
                </x-inputs.button>
            </div>
        @else
            <x-inputs.input noDirty type="checkbox" label="System Wide?" instantSave id="is_system_wide" />
            <div class="py-2">
                <x-inputs.button isBold type="submit">Save</x-inputs.button>
                <x-inputs.button isWarning x-on:click.prevent="deleteSource = true">
                    Delete
                </x-inputs.button>
            </div>
        @endif
    </form>
</div>
