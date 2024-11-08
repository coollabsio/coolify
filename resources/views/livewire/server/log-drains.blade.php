<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Log Drains | Coolify
    </x-slot>
    <x-server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="log-drains" />
        <div class="w-full">
            @if ($server->isFunctional())
                <div class="flex gap-2 items-center">
                    <h2>Log Drains</h2>
                    <x-loading wire:target="instantSave" wire:loading.delay />
                </div>
                <div>Sends service logs to 3rd party tools.</div>
                <div class="flex flex-col gap-4 pt-4">
                    <div class="p-4 border dark:border-coolgray-300">
                        <form wire:submit='submit("newrelic")' class="flex flex-col">
                            <h3>New Relic</h3>
                            <div class="w-32">
                                @if ($isLogDrainAxiomEnabled || $isLogDrainCustomEnabled)
                                    <x-forms.checkbox disabled id="isLogDrainNewRelicEnabled" label="Enabled" />
                                @else
                                    <x-forms.checkbox instantSave id="isLogDrainNewRelicEnabled" label="Enabled" />
                                @endif
                            </div>
                            <div class="flex flex-col gap-4">
                                <div class="flex flex-col w-full gap-2 xl:flex-row">
                                    @if ($server->isLogDrainEnabled())
                                        <x-forms.input disabled type="password" required id="logDrainNewRelicLicenseKey"
                                            label="License Key" />
                                        <x-forms.input disabled required id="logDrainNewRelicBaseUri"
                                            placeholder="https://log-api.eu.newrelic.com/log/v1"
                                            helper="For EU use: https://log-api.eu.newrelic.com/log/v1<br>For US use: https://log-api.newrelic.com/log/v1"
                                            label="Endpoint" />
                                    @else
                                        <x-forms.input type="password" required id="logDrainNewRelicLicenseKey"
                                            label="License Key" />
                                        <x-forms.input required id="logDrainNewRelicBaseUri"
                                            placeholder="https://log-api.eu.newrelic.com/log/v1"
                                            helper="For EU use: https://log-api.eu.newrelic.com/log/v1<br>For US use: https://log-api.newrelic.com/log/v1"
                                            label="Endpoint" />
                                    @endif
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
                            @if ($isLogDrainNewRelicEnabled || $isLogDrainCustomEnabled)
                                <x-forms.checkbox disabled id="isLogDrainAxiomEnabled" label="Enabled" />
                            @else
                                <x-forms.checkbox instantSave id="isLogDrainAxiomEnabled" label="Enabled" />
                            @endif
                        </div>
                        <form wire:submit='submit("axiom")' class="flex flex-col">
                            <div class="flex flex-col gap-4">
                                <div class="flex flex-col w-full gap-2 xl:flex-row">
                                    @if ($server->isLogDrainEnabled())
                                        <x-forms.input disabled type="password" required id="logDrainAxiomApiKey"
                                            label="API Key" />
                                        <x-forms.input disabled required id="logDrainAxiomDatasetName"
                                            label="Dataset Name" />
                                    @else
                                        <x-forms.input type="password" required id="logDrainAxiomApiKey"
                                            label="API Key" />
                                        <x-forms.input required id="logDrainAxiomDatasetName" label="Dataset Name" />
                                    @endif
                                </div>
                            </div>
                            <div class="flex justify-end gap-4 pt-6">
                                <x-forms.button type="submit">
                                    Save
                                </x-forms.button>
                            </div>
                        </form>
                        <h3>Custom FluentBit</h3>
                        <div class="w-32">
                            @if ($isLogDrainNewRelicEnabled || $isLogDrainAxiomEnabled)
                                <x-forms.checkbox disabled id="isLogDrainCustomEnabled" label="Enabled" />
                            @else
                                <x-forms.checkbox instantSave id="isLogDrainCustomEnabled" label="Enabled" />
                            @endif
                        </div>
                        <form wire:submit='submit("custom")' class="flex flex-col">
                            <div class="flex flex-col gap-4">
                                @if ($server->isLogDrainEnabled())
                                    <x-forms.textarea disabled rows="6" required id="logDrainCustomConfig"
                                        label="Custom FluentBit Configuration" />
                                    <x-forms.textarea disabled id="logDrainCustomConfigParser"
                                        label="Custom Parser Configuration" />
                                @else
                                    <x-forms.textarea rows="6" required id="logDrainCustomConfig"
                                        label="Custom FluentBit Configuration" />
                                    <x-forms.textarea id="logDrainCustomConfigParser"
                                        label="Custom Parser Configuration" />
                                @endif

                            </div>
                            <div class="flex justify-end gap-4 pt-6">
                                <x-forms.button type="submit">
                                    Save
                                </x-forms.button>
                            </div>
                        </form>

                    </div>
                </div>
            @else
                <div>Server is not validated. Validate first.</div>
            @endif
        </div>
    </div>
</div>
