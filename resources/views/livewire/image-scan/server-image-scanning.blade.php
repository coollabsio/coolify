<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Vulnerabilities | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    @php
        $costText = 'Scans use up to 1 CPU and 1 GB of memory while they run, and about 1.2 GB of disk, plus temporary space for one image.';
        $pullState = match ($prepareState) {
            'pulling' => 'running',
            'updating_database', 'ready' => 'success',
            'failed' => in_array($failedStep, ['pulling', 'setup', 'connection'], true) ? 'error' : 'success',
            default => 'idle',
        };
        $databaseState = match ($prepareState) {
            'pulling' => 'pending',
            'updating_database' => 'running',
            'ready' => 'success',
            'failed' => $failedStep === 'updating_database' ? 'error' : 'pending',
            default => 'idle',
        };
        $sectionHelper = collect([
            'Scan the images on this server for known vulnerabilities before and after deployments. Coolify also scans the operating system packages of the server every day.',
            "Scanner tools: {$toolVersions}.",
            'Coolify downloads the latest vulnerability database every day, between 02:00 and 05:00 server time.'
                .($builtAt ? ' Database built ' . $builtAt->diffForHumans() . '.' : ''),
            $diskUsed ? "Disk used by scanner data: {$diskUsed}." : null,
            $costText,
        ])->filter()->map(fn (string $line) => e($line))->implode('<br><br>');
    @endphp

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="security" activeSubMenu="vulnerabilities" />

        <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">

            <x-application.settings-section id="server-image-scanning-section" title="Image scanning"
                :helper="$sectionHelper">
                @can('update', $server)
                    <x-slot:actions>
                        @if ($enabled && ($prepareState === null || $prepareState === 'failed'))
                            <x-forms.button type="button" wire:click="prepareScanner">
                                {{ $prepareState === 'failed' ? 'Try again' : 'Get scanner' }}
                            </x-forms.button>
                        @endif
                        {{-- Disabling forgets the cached state, but the files stay on the server. --}}
                        @if (! $enabled || $hasScannerData)
                            <x-modal-confirmation title="Delete scanner data?" buttonTitle="Delete scanner data"
                                isErrorButton submitAction="deleteScannerData" temporaryDisableTwoStepConfirmation
                                :confirmWithText="false" :confirmWithPassword="false" :actions="[
                                    'Coolify removes the stored SBOMs, the vulnerability database, and the scanner images from this server.',
                                    'Scan results in Coolify stay. The next scan gets the scanner again.',
                                ]"
                                step2ButtonText="Delete scanner data" />
                        @endif
                        <div wire:key="image-scanning-change-{{ $enabled ? 'on' : 'off' }}">
                            <x-modal-confirmation
                                title="{{ $enabled ? 'Disable image scanning?' : 'Enable image scanning?' }}"
                                buttonTitle="{{ $enabled ? 'Disable' : 'Enable' }}"
                                submitAction="{{ $enabled ? 'disable' : 'enable' }}"
                                warningMessage="{{ $enabled ? 'New deployments and running images on this server are no longer checked for vulnerabilities. You can enable it again at any time.' : 'Scans run on this server and use its resources. You can disable it again at any time.' }}"
                                temporaryDisableTwoStepConfirmation :confirmWithText="false"
                                :confirmWithPassword="false" :actions="$enabled
                                    ? ['Coolify stops scanning the images and the operating system packages of this server.', 'Scan results and scanner data on the server stay.']
                                    : [$costText]"
                                step2ButtonText="{{ $enabled ? 'Disable image scanning' : 'Enable image scanning' }}"
                                :isHighlightedButton="! $enabled" />
                        </div>
                    </x-slot:actions>
                @endcan
                @if (! $enabled)
                    <x-empty size="sm" icon-name="shield-alert" title="Image scanning is off for {{ $server->name }}."
                        description="{{ $costText }}">
                        @cannot('update', $server)
                            <x-slot:actions>
                                <span class="text-[13px] text-neutral-500 dark:text-fg-faint">Ask a team admin to turn it
                                    on.</span>
                            </x-slot:actions>
                        @endcannot
                    </x-empty>
                @elseif ($prepareState !== 'ready')
                    <div class="divide-y divide-neutral-200 rounded-lg border border-neutral-200 dark:divide-white/[0.08] dark:border-white/[0.08]">
                        <x-checkpoint-item title="Get scanner" icon="download" :status="$pullState"
                            :description="match ($pullState) {
                                'running' => 'Pulling the scanner images.',
                                'success' => 'The scanner images are on this server.',
                                'error' => match ($failedStep) {
                                    'setup' => 'Coolify could not set up the scanner storage on the server. Check that the SSH user can run Docker commands.',
                                    'connection' => 'Coolify could not connect to the server. Check that the server is reachable, then try again.',
                                    default => 'Coolify could not pull the scanner images. Check that the server can reach ' . $registries . '.',
                                },
                                default => 'Not done yet.',
                            }" />
                        <x-checkpoint-item title="Update database" icon="database" :status="$databaseState"
                            :description="match ($databaseState) {
                                'running' => 'Downloading the vulnerability database.',
                                'success' => $builtAt ? 'Database built ' . $builtAt->diffForHumans() . '.' : 'The vulnerability database is up to date.',
                                'error' => 'Coolify could not download the vulnerability database.',
                                default => 'Not done yet.',
                            }" />
                    </div>
                @endif
                <div @class([
                    'mt-5 border-t border-neutral-200 pt-5 dark:border-white/[0.07]' => ! $enabled || $prepareState !== 'ready',
                ])>
                    <livewire:image-scan.scan-settings scope-type="server" :scope-uuid="$server->uuid" :embedded="true"
                        :key="'server-image-scan-settings-'.$server->uuid" />
                </div>
            </x-application.settings-section>

            <livewire:server.security.host-vulnerabilities :server_uuid="$server->uuid" :key="'host-vulnerabilities-'.$server->uuid" />
        </div>
    </div>
</div>
