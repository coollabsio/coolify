@props([
    'title' => 'Settings',
    'subtitle' => 'Instance configuration and maintenance',
])

{{-- Same 1180px shell as the workspace. Title is mobile-only: desktop already
     has Settings in the topbar, layer-2 tabs, and the grouped sidebar. --}}
<div class="mx-auto w-full max-w-[1180px]">
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
