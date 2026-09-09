<div class="application-settings-form flex w-full flex-col gap-6">
    <form wire:submit.prevent="saveTrafficAnalyticsSettings" class="contents">
        @if ($isTrafficAnalyticsEnabled)
            <x-unsaved-bar action="saveTrafficAnalyticsSettings"
                targets="trafficTopn,trafficSampleThreshold,trafficRetention1hDays,trafficRetention1dDays,isGeoipEnabled,geoipRefreshDays,geoipMaxmindLicenseKey" />
        @endif

        <x-application.settings-section id="server-traffic-analytics-settings-section" title="Traffic analytics"
            helper="Control proxy traffic collection, retention, and visitor geolocation for this server.">
            <x-slot:actions>
                <x-forms.button canGate="update" :canResource="$server" wire:click="toggleTrafficAnalytics"
                    wire:confirm="{{ $isTrafficAnalyticsEnabled ? 'Disable' : 'Enable' }} traffic analytics? The proxy and Sentinel will restart, causing a brief connectivity blip for all applications on this server.">
                    {{ $isTrafficAnalyticsEnabled ? 'Disable' : 'Enable' }} traffic analytics
                </x-forms.button>
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
                    icon-name="dashboard" />
            @endif
        </x-application.settings-section>
    </form>
</div>
