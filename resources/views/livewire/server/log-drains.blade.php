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
                        Only one log drain can be active at a time. Disable the current destination before you select
                        another one.
                    </p>
                </x-application.settings-section>

                @php
                    $isActive = $logDrainType !== null;
                    $canUpdate = auth()->user()->can('update', $server);
                    $selectedLabel = collect($logDrainTypes)->firstWhere('value', $selectedLogDrainType)['label'] ?? '';
                @endphp
                <form wire:submit="submit" class="contents">
                    <x-unsaved-bar action="submit" />

                    <x-application.settings-section id="server-log-drain-destination-section" title="Destination"
                        helper="Select where this server sends container logs. Settings are kept for each destination.">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
                            <div class="w-full max-w-sm">
                                <x-forms.listbox canGate="update" :canResource="$server" id="selectedLogDrainType"
                                    label="Destination" live :options="$logDrainTypes"
                                    :disabled="$isActive || !$canUpdate" />
                            </div>
                            @if ($canUpdate)
                                @if ($isActive)
                                    <x-forms.button type="button" wire:click="toggleLogDrain('{{ $logDrainType }}')">
                                        Disable {{ $selectedLabel }}
                                    </x-forms.button>
                                @else
                                    <x-forms.button type="button" isHighlighted
                                        wire:click="toggleLogDrain('{{ $selectedLogDrainType }}')">
                                        Enable {{ $selectedLabel }}
                                    </x-forms.button>
                                @endif
                            @endif
                        </div>
                    </x-application.settings-section>

                    @if ($selectedLogDrainType === 'newrelic')
                        <x-application.settings-section id="server-new-relic-drain-section" title="New Relic"
                            helper="Send logs through the New Relic Log API.">
                            <div class="grid gap-4 lg:grid-cols-2">
                                <x-forms.input canGate="update" :canResource="$server" type="password"
                                    id="logDrainConfig.newrelic.license_key" label="License key"
                                    :disabled="$isActive" />
                                <x-forms.input canGate="update" :canResource="$server"
                                    id="logDrainConfig.newrelic.base_uri" label="Endpoint"
                                    placeholder="https://log-api.eu.newrelic.com/log/v1"
                                    helper="Use the EU or US New Relic Log API endpoint."
                                    :disabled="$isActive" />
                            </div>
                        </x-application.settings-section>
                    @elseif ($selectedLogDrainType === 'axiom')
                        <x-application.settings-section id="server-axiom-drain-section" title="Axiom"
                            helper="Send logs to an Axiom dataset using its ingest API.">
                            <div class="grid gap-4 lg:grid-cols-3">
                                <x-forms.input canGate="update" :canResource="$server" type="password"
                                    id="logDrainConfig.axiom.api_key" label="API key"
                                    :disabled="$isActive" />
                                <x-forms.input canGate="update" :canResource="$server"
                                    id="logDrainConfig.axiom.dataset_name" label="Dataset name"
                                    :disabled="$isActive" />
                                <x-forms.input canGate="update" :canResource="$server"
                                    id="logDrainConfig.axiom.domain" label="Edge domain"
                                    placeholder="us-east-1.aws.edge.axiom.co"
                                    helper="Base domain of the edge deployment of your Axiom organization.<br><br>US: <code>us-east-1.aws.edge.axiom.co</code><br>EU: <code>eu-central-1.aws.edge.axiom.co</code>"
                                    :disabled="$isActive" />
                            </div>
                        </x-application.settings-section>
                    @elseif ($selectedLogDrainType === 'cloudwatch')
                        <x-application.settings-section id="server-cloudwatch-drain-section" title="Amazon CloudWatch Logs"
                            helper="Send container logs directly to CloudWatch Logs using the Docker awslogs logging driver.<br><br>AWS credentials are read by the Docker daemon on this server, not by Coolify. Attach an IAM instance role, or configure <code>AWS_ACCESS_KEY_ID</code> and <code>AWS_SECRET_ACCESS_KEY</code> for the Docker service or in <code>/root/.aws/credentials</code>.<br><br>The credentials need <code>logs:CreateLogStream</code> and <code>logs:PutLogEvents</code>, plus <code>logs:CreateLogGroup</code> if the log group does not exist yet.<br><br>Delivery errors do not stop containers; they appear in the Docker daemon logs (<code>journalctl -u docker</code>). Redeploy or restart resources to apply the logging driver.">
                            <div class="grid gap-4 lg:grid-cols-3">
                                <x-forms.input canGate="update" :canResource="$server"
                                    id="logDrainConfig.cloudwatch.group" label="Log group" placeholder="/coolify/production"
                                    :disabled="$isActive" />
                                <x-forms.input canGate="update" :canResource="$server"
                                    id="logDrainConfig.cloudwatch.stream_prefix" label="Log stream prefix" placeholder="docker/"
                                    helper="Optional. Log streams are named after the container, prefixed with this value (e.g. docker/&lt;container name&gt;)."
                                    :disabled="$isActive" />
                                <x-forms.input canGate="update" :canResource="$server"
                                    id="logDrainConfig.cloudwatch.region" label="Region" placeholder="us-east-1"
                                    :disabled="$isActive" />
                            </div>
                        </x-application.settings-section>
                    @elseif ($selectedLogDrainType === 'custom')
                        <x-application.settings-section id="server-custom-drain-section" title="Custom Fluent Bit"
                            helper="Provide a custom Fluent Bit output and optional parser configuration.">
                            <div class="grid gap-4 lg:grid-cols-2">
                                <x-forms.textarea canGate="update" :canResource="$server" rows="8"
                                    id="logDrainConfig.custom.config" label="Fluent Bit configuration"
                                    :disabled="$isActive" />
                                <x-forms.textarea canGate="update" :canResource="$server" rows="8"
                                    id="logDrainConfig.custom.parser_config" label="Parser configuration"
                                    :disabled="$isActive" />
                            </div>
                        </x-application.settings-section>
                    @endif
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
