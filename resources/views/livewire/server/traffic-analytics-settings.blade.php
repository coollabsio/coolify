<div class="application-settings-form flex w-full flex-col gap-6">
    <form wire:submit.prevent="saveTrafficAnalyticsSettings" class="contents">
        @if ($isTrafficAnalyticsEnabled)
            <x-unsaved-bar action="saveTrafficAnalyticsSettings"
                targets="trafficTopn,trafficSampleThreshold,trafficRetention1hDays,trafficRetention1dDays,isGeoipEnabled,geoipRefreshDays,geoipMaxmindLicenseKey" />
        @endif

        <x-application.settings-section id="server-traffic-analytics-settings-section" title="Traffic analytics"
            helper="Control proxy traffic collection, retention, and visitor geolocation for this server.">
            <x-slot:actions>
                @if ($isTrafficAnalyticsEnabled)
                    <div class="flex items-center gap-3">
                        <x-loading wire:loading.flex wire:target="toggleTrafficAnalytics"
                            text="Restarting Sentinel and proxy..." compact />
                        <x-modal-confirmation title="Disable traffic analytics?"
                            buttonTitle="Disable traffic analytics" submitAction="toggleTrafficAnalytics"
                            :actions="[
                                'Disabling traffic analytics will restart Sentinel and the proxy. Your applications will experience a brief interruption.',
                            ]"
                            warningMessage="Application traffic may be interrupted while Sentinel and the proxy restart."
                            :confirmWithText="false" :confirmWithPassword="false" :ignoreWire="false"
                            step2ButtonText="Disable traffic analytics"
                            :disabled="! auth()->user()->can('update', $server)"
                            :authDisabled="! auth()->user()->can('update', $server)" />
                    </div>
                @endif
            </x-slot:actions>

            @if ($isTrafficAnalyticsEnabled)
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input canGate="update" :canResource="$server" type="number" min="1"
                        id="trafficTopn" label="Top-N cap" required
                        helper="Maximum distinct values kept per dimension (paths, countries, browsers). Overflow folds into Other." />
                    <x-forms.input canGate="update" :canResource="$server" type="number" min="0"
                        id="trafficSampleThreshold" label="Sample threshold" required
                        helper="Events per second above which Sentinel starts sampling. 0 disables sampling." />
                    <x-forms.input canGate="update" :canResource="$server" type="number" min="1"
                        id="trafficRetention1hDays" label="Hourly retention" required
                        helper="Days of hourly rollups to keep. This is the fine-grained history window." />
                    <x-forms.input canGate="update" :canResource="$server" type="number" min="1"
                        id="trafficRetention1dDays" label="Daily retention" required
                        helper="Days of daily rollups to keep before deletion." />
                    <x-forms.listbox canGate="update" :canResource="$server"
                        id="isGeoipEnabled" label="Geolocation"
                        :options="[
                            ['value' => true, 'label' => 'Enabled'],
                            ['value' => false, 'label' => 'Disabled'],
                        ]"
                        helper="Country enrichment from visitor IPs. Disable to skip GeoIP lookups." />
                    <x-forms.input canGate="update" :canResource="$server" type="number" min="1"
                        id="geoipRefreshDays" label="GeoIP refresh interval" required
                        helper="Days between GeoIP database update checks." />
                    <x-forms.input canGate="update" :canResource="$server" type="password"
                        id="geoipMaxmindLicenseKey" label="MaxMind GeoIP license key" placeholder="Optional"
                        helper="Used to download the GeoLite2 database for visitor geolocation. Leave empty to use the default database source." />
                </div>
            @else
                <x-empty size="sm" title="Traffic analytics is disabled"
                    description="Enable traffic analytics to collect proxy access logs and geolocate visitor traffic."
                    icon-name="dashboard">
                    <x-slot:contents>
                        <div class="flex items-center gap-3">
                            <x-loading wire:loading.flex wire:target="toggleTrafficAnalytics"
                                text="Restarting Sentinel and proxy..." compact />
                            <x-modal-confirmation title="Enable traffic analytics?"
                                buttonTitle="Enable traffic analytics" submitAction="toggleTrafficAnalytics"
                                :actions="[
                                    'Enabling traffic analytics will restart Sentinel and the proxy. Your applications will experience a brief interruption.',
                                ]"
                                warningMessage="Application traffic may be interrupted while Sentinel and the proxy restart."
                                :confirmWithText="false" :confirmWithPassword="false" :ignoreWire="false"
                                step2ButtonText="Enable traffic analytics" isHighlightedButton
                                :disabled="! auth()->user()->can('update', $server)"
                                :authDisabled="! auth()->user()->can('update', $server)" />
                        </div>
                    </x-slot:contents>
                </x-empty>
            @endif
        </x-application.settings-section>
    </form>
</div>
