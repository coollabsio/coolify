@php
    $onOff = [['value' => '0', 'label' => 'Off'], ['value' => '1', 'label' => 'On']];
    $isApplication = $scopeType === 'application';
    $rulesHelper = match ($scopeType) {
        'instance' => 'Defaults for every team. A team, server, or app can set its own value.',
        'team' => 'Defaults for the servers and apps of this team. A server or app can set its own value.',
        'server' => 'Defaults for the apps on this server. An app can set its own value.',
        default => 'Rules for this app.',
    };
    $gateHelper = $isApplication
        ? 'Stop a deploy when the image has vulnerabilities with a fix at the alert level.'
        : 'Default for apps. Stop a deploy when the image has vulnerabilities with a fix at the alert level.';
@endphp

<div class="flex flex-col gap-6">
    <x-application.settings-section id="image-scan-rules-section" title="Rules" :helper="$rulesHelper">
        <div class="flex flex-col gap-4">
            @if ($isApplication || $scopeType === 'team')
                <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    @if ($isApplication)
                        <x-forms.listbox id="appScanEnabled" label="Scan this app" onChange="$commit"
                            :disabled="! $canEdit" helper="Off stops the scans of this app's image."
                            :options="[['value' => '1', 'label' => 'On'], ['value' => '0', 'label' => 'Off']]" />
                        @if ($isDockerImage)
                            <x-forms.listbox id="buildsByTeam" label="Who builds this image" onChange="$commit"
                                :disabled="! $canEdit"
                                helper="Coolify detects whether your team builds this image. Set it when the detection is wrong."
                                :options="[
                                    ['value' => '', 'label' => 'Detect automatically'],
                                    ['value' => '1', 'label' => 'My team'],
                                    ['value' => '0', 'label' => 'Someone else'],
                                ]" />
                        @endif
                    @else
                        <x-forms.listbox id="scanNewServers" label="Scan images on new servers" onChange="$commit"
                            :disabled="! $canEdit"
                            helper="Pre-selects image scanning when someone adds a server to this team. They can change it."
                            :options="$onOff" />
                    @endif
                </div>
            @endif

            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <x-forms.inherited-listbox id="alertLevel" label="Alert on" :disabled="! $canEdit"
                    :inheritLabel="$inheritLabels['alert_level'] ?? null" :options="$alertLevelOptions"
                    helper="Coolify alerts when the image has vulnerabilities with a fix at this level or higher." />
                <div class="min-w-0">
                    <x-forms.inherited-listbox id="gateEnabled" label="Block deploys"
                        :disabled="! $canEdit || ! $appScanOn" :inheritLabel="$inheritLabels['gate_enabled'] ?? null"
                        :options="$onOff" :helper="$gateHelper" />
                    @if (! $appScanOn)
                        <p class="mt-1.5 text-[12px] text-neutral-500 dark:text-fg-faint">Turn on the scan for this app first.</p>
                    @endif
                </div>
                <x-forms.inherited-listbox id="onScanError" label="If the scan cannot run" :disabled="! $canEdit"
                    :inheritLabel="$inheritLabels['on_scan_error'] ?? null" :options="$onScanErrorOptions"
                    helper="Applies when Block deploys is on." />
            </div>

            @if (in_array($scopeType, ['instance', 'team', 'server'], true))
                <p class="text-[12px] text-neutral-500 dark:text-fg-faint">Services and databases use only the alert setting.</p>
            @endif
        </div>
    </x-application.settings-section>

    @if ($showsIgnores)
        <x-application.settings-section id="image-scan-ignores-section" title="Ignored vulnerabilities"
            helper="Ignored vulnerabilities do not count for alerts or Block deploys. Ignore a vulnerability from the findings of a resource."
            flush>
            @if ($ignores->isEmpty())
                <x-empty size="sm" title="No ignored vulnerabilities." />
            @else
                <div class="data-table">
                    <div class="data-table-header image-scan-ignores-table-grid">
                        <span>Vulnerability or package</span>
                        <span>Reason</span>
                        <span>Expires</span>
                        <span>Set for</span>
                        <span class="text-right">Actions</span>
                    </div>
                    @foreach ($ignores as $ignore)
                        @php($isTeamWide = $ignore->resource_id === null)
                        <div wire:key="image-scan-ignore-{{ $ignore->uuid }}"
                            class="data-table-row image-scan-ignores-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                            <div class="min-w-0 truncate text-[12px] text-black dark:text-fg">
                                @if ($ignore->vulnerability_id)
                                    <span class="font-mono">{{ $ignore->vulnerability_id }}</span>
                                @else
                                    All vulnerabilities in <span class="font-mono">{{ $ignore->package }}</span>
                                @endif
                            </div>
                            <div class="min-w-0 truncate text-[12px] text-neutral-600 dark:text-fg-dim"
                                title="{{ $ignore->reason }}">{{ $ignore->reason }}</div>
                            <div class="text-[11px] text-neutral-500 dark:text-fg-dim">
                                {{ $ignore->expires_at?->format('Y-m-d') ?? 'Never' }}
                            </div>
                            <div class="min-w-0 text-[11px] text-neutral-500 dark:text-fg-dim">
                                {{ $isTeamWide ? 'All team resources' : 'This resource' }}
                            </div>
                            <div class="flex justify-end">
                                @if ($isApplication && $isTeamWide)
                                    @if ($showsTeamSettingsLink)
                                        <a href="{{ route('team.image-scanning') }}" wire:navigate
                                            class="inline-flex h-7 items-center rounded-md px-2 text-[11px] font-medium text-neutral-600 hover:bg-neutral-100 dark:text-fg-dim dark:hover:bg-white/[0.06]">
                                            Team settings
                                        </a>
                                    @endif
                                @elseif ($canEdit)
                                    <x-modal-confirmation title="Remove this ignore?"
                                        submitAction="removeIgnore('{{ $ignore->uuid }}')" :actions="[
                                            'The vulnerability counts again for alerts and Block deploys.',
                                        ]"
                                        :confirmWithText="false" :confirmWithPassword="false"
                                        temporaryDisableTwoStepConfirmation step2ButtonText="Remove">
                                        <x-slot:trigger>
                                            <button type="button"
                                                class="inline-flex h-7 items-center rounded-md px-2 text-[11px] font-medium text-error transition-colors hover:bg-error/10">
                                                Remove
                                            </button>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-application.settings-section>
    @endif
</div>
