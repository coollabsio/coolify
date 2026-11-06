<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Image Scanning | Coolify
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
    @endphp

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="security" activeSubMenu="image-scanning" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            <x-application.settings-section id="server-image-scanning-section" title="Image scanning"
                helper="Scan the images on this server for known vulnerabilities before and after deployments.">
                @if (! $allowed)
                    <x-empty size="sm" title="Image scanning is turned off for this Coolify instance." />
                @elseif (! $enabled)
                    <x-empty size="sm" icon-name="shield-alert" title="Image scanning is off for {{ $server->name }}."
                        description="{{ $costText }}">
                        <x-slot:actions>
                            @can('update', $server)
                                <x-modal-confirmation title="Turn on image scanning?" buttonTitle="Turn on image scanning"
                                    isHighlightedButton submitAction="enable" temporaryDisableTwoStepConfirmation
                                    :confirmWithText="false" :confirmWithPassword="false" :actions="[$costText]"
                                    step2ButtonText="Turn on image scanning" />
                            @else
                                <span class="text-[13px] text-neutral-500 dark:text-fg-faint">Ask a team admin to turn it
                                    on.</span>
                            @endcan
                        </x-slot:actions>
                    </x-empty>
                @else
                    <div class="flex flex-col gap-4">
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

                        <div class="flex flex-col gap-1 text-[12px] text-neutral-500 dark:text-fg-faint">
                            <p>Scanner tools: {{ $toolVersions }}.</p>
                            @if ($diskUsed)
                                <p>Disk used by scanner data: {{ $diskUsed }}.</p>
                            @endif
                            <p>{{ $costText }}</p>
                        </div>

                        @can('update', $server)
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($prepareState === null || $prepareState === 'failed')
                                    <x-forms.button type="button" wire:click="prepareScanner">
                                        {{ $prepareState === 'failed' ? 'Try again' : 'Get scanner' }}
                                    </x-forms.button>
                                @endif
                                <x-forms.button type="button" wire:click="disable">
                                    Turn off image scanning
                                </x-forms.button>
                                <x-modal-confirmation title="Delete scanner data?" buttonTitle="Delete scanner data"
                                    isErrorButton submitAction="deleteScannerData" temporaryDisableTwoStepConfirmation
                                    :confirmWithText="false" :confirmWithPassword="false" :actions="[
                                        'Coolify removes the stored SBOMs, the vulnerability database, and the scanner images from this server.',
                                        'Scan results in Coolify stay. The next scan gets the scanner again.',
                                    ]"
                                    step2ButtonText="Delete scanner data" />
                            </div>
                        @endcan
                    </div>
                @endif
            </x-application.settings-section>

            @if ($allowed)
                <livewire:image-scan.scan-settings scope-type="server" :scope-uuid="$server->uuid"
                    :key="'server-image-scan-settings-'.$server->uuid" />
            @endif
        </div>
    </div>
</div>
