{{-- Railway-styled global sidebar. Replaces Coolify's classic navbar while keeping
     every feature reachable. Used for all non-project pages. --}}
@php
    $navItem = function (bool $active): string {
        return $active ? 'rw-nav-item rw-nav-item-active' : 'rw-nav-item hover:rw-nav-item-hover';
    };
    $user = auth()->user();
@endphp

<aside class="flex flex-col w-60 shrink-0 h-screen border-r" style="border-color: var(--color-rw-border); background: var(--color-rw-surface);">
    {{-- Workspace / brand --}}
    <div class="flex items-center gap-2 px-3 h-14 border-b" style="border-color: var(--color-rw-border);">
        <a href="{{ route('dashboard') }}" wire:navigate class="rw-icon-btn hover:rw-icon-btn-hover -ml-1 shrink-0">
            <x-railway.logo size="w-5 h-5" />
        </a>
        <div class="min-w-0 flex-1">
            <livewire:switch-team />
        </div>
    </div>

    {{-- Search --}}
    <div class="px-2 pt-2">
        <button type="button" @click="$dispatch('open-global-search')"
            class="flex items-center gap-2 w-full rounded-md border px-2.5 h-8 text-[13px] text-rw-subtle hover:text-rw-text"
            style="border-color: var(--color-rw-border); background: var(--color-rw-elevated);">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <span class="flex-1 text-left">Search…</span>
            <kbd class="text-[11px] rounded px-1" style="background: var(--color-rw-panel);">/</kbd>
        </button>
    </div>

    {{-- Primary nav --}}
    <nav class="flex flex-col gap-0.5 p-2 overflow-y-auto scrollbar flex-1">
        <a href="{{ route('dashboard') }}" wire:navigate class="{{ $navItem(request()->is('/') || request()->is('railway') || request()->is('railway/*')) }}">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4l-8 4 8 4 8-4-8-4M4 12l8 4 8-4M4 16l8 4 8-4"/></svg>
            Projects
        </a>

        <div class="mx-2 my-1.5 border-t" style="border-color: var(--color-rw-border);"></div>
        <div class="px-2.5 py-1 text-[11px] font-semibold text-rw-subtle uppercase tracking-wide">Infrastructure</div>

        <a href="/servers" wire:navigate class="{{ $navItem(request()->is('servers') || request()->is('server/*')) }}">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="6" rx="2"/><path d="M15 20H6a3 3 0 0 1-3-3v-2a3 3 0 0 1 3-3h12M7 8h.01M7 16h.01"/></svg>
            Servers
        </a>
        <a href="{{ route('source.all') }}" wire:navigate class="{{ $navItem(request()->is('source*')) }}">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6.8 1.4 1.4 6.8a1.5 1.5 0 0 0 0 2.1l5.4 5.4a1.5 1.5 0 0 0 2.1 0l5.4-5.4a1.5 1.5 0 0 0 0-2.1L8.9 1.4a1.5 1.5 0 0 0-2.1 0Z"/><path d="M7.5 4.5v1M7.5 7.5v4M10.5 7.5v1"/></svg>
            Sources
        </a>
        <a href="{{ route('destination.index') }}" wire:navigate class="{{ $navItem(request()->is('destination*')) }}">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4 3 8v12l6-3 6 3 6-4V4l-6 3-6-3Z"/><path d="M9 4v13M15 7v13"/></svg>
            Destinations
        </a>
        <a href="{{ route('storage.index') }}" wire:navigate class="{{ $navItem(request()->is('storages*')) }}">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12a8 3 0 0 0 16 0V6M4 12a8 3 0 0 0 16 0"/></svg>
            S3 Storages
        </a>
        <a href="{{ route('shared-variables.index') }}" wire:navigate class="{{ $navItem(request()->is('shared-variables*')) }}">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4C2.5 9 2.5 14 5 20M19 4c2.5 5 2.5 10 0 16M9 9h1c1 0 1 1 2 3.5S13 16 14 16h1"/></svg>
            Shared Variables
        </a>

        <div class="mx-2 my-1.5 border-t" style="border-color: var(--color-rw-border);"></div>
        <div class="px-2.5 py-1 text-[11px] font-semibold text-rw-subtle uppercase tracking-wide">Account</div>

        <a href="{{ route('notifications.email') }}" wire:navigate class="{{ $navItem(request()->is('notifications*')) }}">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
            Notifications
        </a>
        <a href="{{ route('security.private-key.index') }}" wire:navigate class="{{ $navItem(request()->is('security*')) }}">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="m16.5 3.8 3.6 3.6a2.9 2.9 0 0 1 0 4.1l-2.6 2.6a2.9 2.9 0 0 1-4.1 0l-.3-.3-6.5 6.6a2 2 0 0 1-1.3.6H4a1 1 0 0 1-1-1v-1.2a2 2 0 0 1 .5-1.3L4 17h2v-2h2v-2l2.1-2.1-.3-.3a2.9 2.9 0 0 1 0-4.1l2.6-2.6a2.9 2.9 0 0 1 4.1 0ZM15 9h.01"/></svg>
            Keys &amp; Tokens
        </a>
        <a href="{{ route('tags.show') }}" wire:navigate class="{{ $navItem(request()->is('tags*')) }}">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8v4.2a2 2 0 0 0 .6 1.4l5.7 5.7a2.4 2.4 0 0 0 3.4 0l3.6-3.6a2.4 2.4 0 0 0 0-3.4l-5.7-5.7A2 2 0 0 0 9.2 6H5a2 2 0 0 0-2 2M7 10h.01"/></svg>
            Tags
        </a>
        @can('canAccessTerminal')
            <a href="{{ route('terminal') }}" wire:navigate class="{{ $navItem(request()->is('terminal*')) }}">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 7 5 5-5 5M12 19h7"/></svg>
                Terminal
            </a>
        @endcan
        <a href="{{ route('profile') }}" wire:navigate class="{{ $navItem(request()->is('profile*')) }}">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="10" r="3"/><path d="M6.2 18.8a4 4 0 0 1 3.8-2.8h4a4 4 0 0 1 3.8 2.9"/></svg>
            Profile
        </a>
        <a href="{{ route('team.index') }}" wire:navigate class="{{ $navItem(request()->is('team*')) }}">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="13" r="2"/><path d="M8 21v-1a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v1M17 7a2 2 0 1 0-4 0M17 10h2a2 2 0 0 1 2 2v1M7 7a2 2 0 1 0 4 0M3 13v-1a2 2 0 0 1 2-2h2"/></svg>
            Teams
        </a>
        @if (isCloud() && auth()->user()->isAdmin())
            <a href="{{ route('subscription.show') }}" wire:navigate class="{{ $navItem(request()->is('subscription*')) }}">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h.01M11 15h2"/></svg>
                Subscription
            </a>
        @endif
        @if (isInstanceAdmin())
            <a href="{{ route('settings.index') }}" wire:navigate class="{{ $navItem(request()->is('settings*')) }}">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                Settings
            </a>
        @endif
        @if ((isCloud() || isDev()) && (isInstanceAdmin() || session('impersonating')))
            <a href="{{ route('admin.index') }}" wire:navigate class="{{ $navItem(request()->is('admin*')) }}">
                <svg class="w-4 h-4 text-pink-500" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2c4 4 8 8 8 13a8 8 0 1 1-16 0c0-2 1-4 2.5-6 .5 1 1.5 2 2.5 2 0-2 .5-4 3-9Z"/></svg>
                Admin
            </a>
        @endif
    </nav>

    {{-- Bottom --}}
    <div class="border-t" style="border-color: var(--color-rw-border);">
        <div class="flex items-center gap-1 px-2 py-2">
            <div class="flex-1"><livewire:settings-dropdown trigger="changelog-sidebar" /></div>
            <a href="https://coolify.io/sponsorships" target="_blank" rel="noopener" class="rw-icon-btn hover:rw-icon-btn-hover w-8 h-8" title="Sponsor us">
                <svg class="w-4 h-4 text-pink-500" viewBox="0 0 24 24" fill="currentColor"><path d="M12 21 4.5 13.5a5 5 0 1 1 7.5-6.6 5 5 0 1 1 7.5 6.6L12 21Z"/></svg>
            </a>
            <form action="/logout" method="POST" class="contents">
                @csrf
                <button type="submit" class="rw-icon-btn hover:rw-icon-btn-hover w-8 h-8" title="Logout">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                </button>
            </form>
        </div>
        <div class="flex items-center gap-2.5 px-3 pb-3">
            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-[10px] font-semibold text-white shrink-0" style="background: linear-gradient(135deg,#5b8def,#8b5cf6);">
                {{ strtoupper(substr($user->name ?? $user->email ?? 'U', 0, 1)) }}
            </span>
            <span class="text-[12px] text-rw-muted truncate flex-1">{{ $user->email }}</span>
        </div>
    </div>
</aside>
