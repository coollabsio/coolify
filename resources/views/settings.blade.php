<x-layout>
    <livewire:settings.form :settings="$settings" />
    @if (auth()->user()->isPartOfRootTeam())
        <livewire:force-upgrade />
    @endif
    <h3 class="pb-0">Notification</h3>
    <div class="pb-4 text-sm">Notification (email, discord, etc) settings for Coolify.</div>
    <h4>Email</h4>
    <livewire:settings.email :model="$settings" />
</x-layout>
