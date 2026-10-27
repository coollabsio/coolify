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
        ? 'Stop a deploy when the new image has security problems.'
        : 'Default for apps. Stop a deploy when the new image has security problems.';
    $ignoresHelper = 'Ignored vulnerabilities do not count for alerts or Block deploys. Ignore a vulnerability from the findings of a resource.';
@endphp

<div class="flex flex-col gap-6">
    @if ($isApplication)
        <x-application.settings-section id="image-scan-settings-section" title="Settings" collapsible :open="! $appScanOn">
            <x-application.settings-subsection title="Rules" :helper="$rulesHelper" first>
                @include('livewire.image-scan.partials.scan-rules')
            </x-application.settings-subsection>
            @if ($showsIgnores)
                <x-application.settings-subsection title="Ignored vulnerabilities" :helper="$ignoresHelper">
                    <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-white/[0.08]">
                        @include('livewire.image-scan.partials.scan-ignores')
                    </div>
                </x-application.settings-subsection>
            @endif
        </x-application.settings-section>
    @else
        <x-application.settings-section id="image-scan-rules-section" title="Rules" :helper="$rulesHelper">
            @include('livewire.image-scan.partials.scan-rules')
        </x-application.settings-section>

        @if ($showsIgnores)
            <x-application.settings-section id="image-scan-ignores-section" title="Ignored vulnerabilities"
                :helper="$ignoresHelper" flush>
                @include('livewire.image-scan.partials.scan-ignores')
            </x-application.settings-section>
        @endif
    @endif
</div>
