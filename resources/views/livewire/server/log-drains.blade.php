<div>
    <x-server.navbar :server="$server" :parameters="$parameters" />
    <h2>Log Drains</h2>
    <div class="pb-4">Sends resource logs to external services.</div>
    <div class="flex flex-col gap-4 pt-4">
        <div class="p-4 border border-coolgray-500">
            <form wire:submit.prevent='submit("newrelic")' class="flex flex-col">
                <h3>New Relic</h3>
                <div class="w-32">
                    <x-forms.checkbox instantSave='instantSave("newrelic")'
                        id="server.settings.is_logdrain_newrelic_enabled" label="Enabled" />
                </div>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col w-full gap-2 xl:flex-row">
                        <x-forms.input type="password" required id="server.settings.logdrain_newrelic_license_key"
                            label="License Key" />
                        <x-forms.input required id="server.settings.logdrain_newrelic_base_uri"
                            placeholder="https://log-api.eu.newrelic.com/log/v1" label="Endpoint (EU / US)" />
                    </div>
                </div>
                <div class="flex justify-end gap-4 pt-6">
                    <x-forms.button type="submit">
                        Save
                    </x-forms.button>
                </div>
            </form>
            <h3>Highlight.io</h3>
            <div class="w-32">
                <x-forms.checkbox instantSave='instantSave("highlight")'
                    id="server.settings.is_logdrain_highlight_enabled" label="Enabled" />
            </div>
            <form wire:submit.prevent='submit("highlight")' class="flex flex-col">
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col w-full gap-2 xl:flex-row">
                        <x-forms.input type="password" required id="server.settings.logdrain_highlight_project_id"
                            label="Project Id" />
                    </div>
                </div>
                <div class="flex justify-end gap-4 pt-6">
                    <x-forms.button type="submit">
                        Save
                    </x-forms.button>
                </div>
            </form>
            <h3>Axiom</h3>
            <div class="w-32">
                <x-forms.checkbox instantSave='instantSave("axiom")' id="server.settings.is_logdrain_axiom_enabled"
                    label="Enabled" />
            </div>
            <form wire:submit.prevent='submit("axiom")' class="flex flex-col">
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col w-full gap-2 xl:flex-row">
                        <x-forms.input type="password" required id="server.settings.logdrain_axiom_api_key"
                            label="API Key" />
                        <x-forms.input required id="server.settings.logdrain_axiom_dataset_name" label="Dataset Name" />
                    </div>
                </div>
                <div class="flex justify-end gap-4 pt-6">
                    <x-forms.button type="submit">
                        Save
                    </x-forms.button>
                </div>
            </form>
        </div>
    </div>
</div>
