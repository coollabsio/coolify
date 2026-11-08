<div>
    <x-slot:title>
        Team Image Scanning | Coolify
    </x-slot>

    <x-team.settings-layout>
        <div class="application-settings-form flex flex-col gap-6">
            <livewire:image-scan.scan-settings scope-type="team" :scope-uuid="(string) $teamId"
                :key="'team-image-scan-settings-'.$teamId" />
        </div>
    </x-team.settings-layout>
</div>
