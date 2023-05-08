<div>
    <h3>Change Github App</h3>
    <form wire:submit.prevent='submit'>
        <x-inputs.input id="github_app.name" label="App Name" required />
        <x-inputs.input noDirty type="checkbox" label="System Wide?" instantSave id="is_system_wide" />
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
            <x-inputs.button type="submit">Save</x-inputs.button>
        @else
            <div class="py-2">
                <x-inputs.button type="submit">Save</x-inputs.button>

            </div>
        @endif
    </form>
    <form x-data="ContactForm()" @submit.prevent="submitForm">
        <x-inputs.input id="host" noLabel />
        <button type="submit">Create GitHub Application</button>
    </form>
    <script>
        function ContactForm() {
            return {
                host: "",
                submitForm() {
                    console.log(JSON.stringify(this.host));
                },
            };
        }
    </script>
</div>
