@props([
    'title' => 'Settings',
    'subtitle' => 'Instance configuration and maintenance',
])

{{-- Same 1180px shell as the workspace. Title hidden only at xl+ (full desktop). --}}
<div class="mx-auto w-full max-w-none">
    <x-dashboard.navbar section="settings" :title="$title" :subtitle="$subtitle" :titleOnDesktop="false">
        @isset($titleActions)
            <x-slot:titleActions>
                {{ $titleActions }}
            </x-slot:titleActions>
        @endisset
        @isset($actions)
            <x-slot:actions>
                {{ $actions }}
            </x-slot:actions>
        @endisset
    </x-dashboard.navbar>
</div>
