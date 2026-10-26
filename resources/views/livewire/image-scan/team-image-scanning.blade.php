<div>
    <x-slot:title>
        Team Image Scanning | Coolify
    </x-slot>

    <x-team.settings-layout>
        <div class="application-settings-form flex flex-col gap-6">
            @if ($allowed)
                <livewire:image-scan.scan-settings scope-type="team" :scope-uuid="(string) $teamId"
                    :key="'team-image-scan-settings-'.$teamId" />
            @else
                <x-application.settings-section title="Image Scanning">
                    <x-empty size="sm" title="Image scanning is turned off for this Coolify instance." />
                </x-application.settings-section>
            @endif
        </div>
    </x-team.settings-layout>
</div>
