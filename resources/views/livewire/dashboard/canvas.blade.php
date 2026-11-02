<div class="flex h-full flex-col">
    <x-slot:title>
        Dashboard > Canvas | Coolify
    </x-slot>
    {{-- data-poll: deployments start without a broadcast event, so the canvas reloads while it is open. --}}
    <div data-environment-canvas data-endpoint="/dashboard/canvas" data-poll="5"
        data-team="{{ $teamId }}" data-user="{{ auth()->id() }}" class="relative min-h-0 flex-1" wire:ignore>
        {{-- The canvas moves this group into its toolbar. --}}
        <div data-ec-views class="flex items-center gap-2">
            <x-canvas-view-toggle on-canvas :views="['list' => ['List view', 'unordered-list']]"
                :list-url="route('dashboard')" :canvas-url="route('dashboard.canvas')" />
        </div>
    </div>
</div>
