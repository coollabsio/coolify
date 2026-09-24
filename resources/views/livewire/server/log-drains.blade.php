<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Log Drains | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="log-drains" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            @if ($server->isFunctional())
                <x-application.settings-section id="server-log-drains-overview-section" title="Log drains"
                    helper="Forward container logs from this server to one external destination.">
                    <x-slot:actions>
                        <x-status-badge :status="$server->isLogDrainEnabled() ? 'Active' : 'Not configured'"
                            :type="$server->isLogDrainEnabled() ? 'success' : 'neutral'" />
                    </x-slot:actions>
                    <p class="text-sm leading-6 text-neutral-600 dark:text-fg-dim">
                        Only one log drain can be active at a time. Disable the current destination before enabling
                        another provider.
                    </p>
                </x-application.settings-section>

                <form wire:submit="submit" class="contents">
                    <x-unsaved-bar action="submit" />

                    <x-application.settings-section id="server-new-relic-drain-section" title="New Relic"
                        helper="Send logs through the New Relic Log API.">
                        <div class="grid gap-4 lg:grid-cols-3">
                            <x-forms.listbox canGate="update" :canResource="$server" id="isLogDrainNewRelicEnabled" label="Status"
                                onChange="instantSave" :options="[
                                    ['value' => false, 'label' => 'Disabled'],
                                    ['value' => true, 'label' => 'Enabled'],
                                ]"
                                :disabled="$isLogDrainAxiomEnabled || $isLogDrainCustomEnabled || $isLogDrainCloudwatchEnabled || !auth()->user()->can('update', $server)" />
                            <x-forms.input canGate="update" :canResource="$server" type="password" required
                                id="logDrainNewRelicLicenseKey" label="License key"
                                :disabled="$server->isLogDrainEnabled()" />
                            <x-forms.input canGate="update" :canResource="$server" required
                                id="logDrainNewRelicBaseUri" label="Endpoint"
                                placeholder="https://log-api.eu.newrelic.com/log/v1"
                                helper="Use the EU or US New Relic Log API endpoint."
                                :disabled="$server->isLogDrainEnabled()" />
                        </div>
                    </x-application.settings-section>
                    <x-application.settings-section id="server-axiom-drain-section" title="Axiom"
                        helper="Send logs to an Axiom dataset using its ingest API.">
                        <div class="grid gap-4 lg:grid-cols-3">
                            <x-forms.listbox canGate="update" :canResource="$server" id="isLogDrainAxiomEnabled" label="Status"
                                onChange="instantSave" :options="[
                                    ['value' => false, 'label' => 'Disabled'],
                                    ['value' => true, 'label' => 'Enabled'],
                                ]"
                                :disabled="$isLogDrainNewRelicEnabled || $isLogDrainCustomEnabled || $isLogDrainCloudwatchEnabled || !auth()->user()->can('update', $server)" />
                            <x-forms.input canGate="update" :canResource="$server" type="password" required
                                id="logDrainAxiomApiKey" label="API key"
                                :disabled="$server->isLogDrainEnabled()" />
                            <x-forms.input canGate="update" :canResource="$server" required
                                id="logDrainAxiomDatasetName" label="Dataset name"
                                :disabled="$server->isLogDrainEnabled()" />
                        </div>
                    </x-application.settings-section>
                    <x-application.settings-section id="server-cloudwatch-drain-section" title="Amazon CloudWatch Logs"
                        helper="Send container logs directly to CloudWatch Logs using the Docker awslogs logging driver.">
                        <div class="mb-4 max-w-sm">
                            <x-forms.listbox canGate="update" :canResource="$server" id="isLogDrainCloudwatchEnabled" label="Status"
                                onChange="instantSave" :options="[
                                    ['value' => false, 'label' => 'Disabled'],
                                    ['value' => true, 'label' => 'Enabled'],
                                ]"
                                :disabled="$isLogDrainNewRelicEnabled || $isLogDrainAxiomEnabled || $isLogDrainCustomEnabled || !auth()->user()->can('update', $server)" />
                        </div>
                        <p class="mb-4 text-sm leading-6 text-neutral-600 dark:text-fg-dim">
                            AWS credentials are read by the Docker daemon on this server, not by Coolify. Attach an IAM
                            instance role, or configure <code>AWS_ACCESS_KEY_ID</code>/<code>AWS_SECRET_ACCESS_KEY</code>
                            for the Docker service or in <code>/root/.aws/credentials</code>. The credentials need
                            <code>logs:CreateLogGroup</code>, <code>logs:CreateLogStream</code> and
                            <code>logs:PutLogEvents</code>. The log group is created if it does not exist. Redeploy or
                            restart resources to apply the logging driver.
                        </p>
                        <div class="grid gap-4 lg:grid-cols-3">
                            <x-forms.input canGate="update" :canResource="$server" required
                                id="logDrainCloudwatchGroup" label="Log group" placeholder="/coolify/production"
                                :disabled="$server->isLogDrainEnabled()" />
                            <x-forms.input canGate="update" :canResource="$server"
                                id="logDrainCloudwatchStreamPrefix" label="Log stream prefix" placeholder="docker/"
                                helper="Optional. Log streams are named after the container, prefixed with this value (e.g. docker/&lt;container name&gt;)."
                                :disabled="$server->isLogDrainEnabled()" />
                            <x-forms.input canGate="update" :canResource="$server" required
                                id="logDrainCloudwatchRegion" label="Region" placeholder="us-east-1"
                                :disabled="$server->isLogDrainEnabled()" />
                        </div>
                    </x-application.settings-section>
                    <x-application.settings-section id="server-custom-drain-section" title="Custom Fluent Bit"
                        helper="Provide a custom Fluent Bit output and optional parser configuration.">
                        <div class="mb-4 max-w-sm">
                            <x-forms.listbox canGate="update" :canResource="$server" id="isLogDrainCustomEnabled" label="Status"
                                onChange="instantSave" :options="[
                                    ['value' => false, 'label' => 'Disabled'],
                                    ['value' => true, 'label' => 'Enabled'],
                                ]"
                                :disabled="$isLogDrainNewRelicEnabled || $isLogDrainAxiomEnabled || $isLogDrainCloudwatchEnabled || !auth()->user()->can('update', $server)" />
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.textarea canGate="update" :canResource="$server" rows="8" required
                                id="logDrainCustomConfig" label="Fluent Bit configuration"
                                :disabled="$server->isLogDrainEnabled()" />
                            <x-forms.textarea canGate="update" :canResource="$server" rows="8"
                                id="logDrainCustomConfigParser" label="Parser configuration"
                                :disabled="$server->isLogDrainEnabled()" />
                        </div>
                    </x-application.settings-section>
                </form>
            @else
                <x-application.settings-section title="Log drains"
                    helper="Forward container logs from this server to an external destination.">
                    <x-empty size="sm" title="Server validation required"
                        description="Validate this server before configuring log drains."
                        icon-name="notifications" />
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>
