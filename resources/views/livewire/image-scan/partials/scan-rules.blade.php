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
        <div class="min-w-0">
            <x-forms.inherited-listbox id="gateMode" label="Block deploys"
                :disabled="! $canEdit || ! $appScanOn" :inheritLabel="$inheritLabels['gate_mode'] ?? null"
                :options="$gateModeOptions" :helper="$gateHelper" />
            @if (! $appScanOn)
                <p class="mt-1.5 text-[12px] text-neutral-500 dark:text-fg-faint">Turn on the scan for this app first.</p>
            @endif
        </div>
        <x-forms.inherited-listbox id="onScanError" label="If the scan cannot run" :disabled="! $canEdit"
            :inheritLabel="$inheritLabels['on_scan_error'] ?? null" :options="$onScanErrorOptions"
            helper="Applies when Block deploys is On or Strict." />
    </div>

    @if (in_array($scopeType, ['instance', 'team', 'server'], true))
        <p class="text-[12px] text-neutral-500 dark:text-fg-faint">Block deploys applies only to apps.</p>
    @endif
</div>
