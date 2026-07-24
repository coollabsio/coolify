@props(['active' => 'projects'])

@php
    $team = currentTeam();
    $user = auth()->user();
@endphp

<aside class="flex flex-col w-60 shrink-0 h-screen border-r" style="border-color: var(--color-rw-border); background: var(--color-rw-surface);">
    {{-- Workspace header --}}
    <div class="flex items-center gap-2.5 px-3 h-14 border-b" style="border-color: var(--color-rw-border);">
        <a href="{{ route('railway.projects') }}" wire:navigate class="rw-icon-btn hover:rw-icon-btn-hover -ml-1">
            <x-railway.logo size="w-5 h-5" />
        </a>
        <div class="flex items-center gap-2 flex-1 min-w-0">
            <span class="inline-block w-5 h-5 rounded-full shrink-0" style="background: linear-gradient(135deg,#8b5cf6,#e5484d);"></span>
            <div class="min-w-0">
                <div class="text-[13px] font-semibold text-rw-text truncate leading-tight">{{ $team->name }}</div>
                <div class="text-[10px] font-medium text-rw-subtle uppercase tracking-wide leading-tight">Pro</div>
            </div>
            <svg class="w-3.5 h-3.5 text-rw-subtle shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
        </div>
    </div>

    {{-- Primary nav --}}
    <nav class="flex flex-col gap-0.5 p-2">
        <a href="{{ route('railway.projects') }}" wire:navigate class="rw-nav-item hover:rw-nav-item-hover {{ $active === 'projects' ? 'rw-nav-item-active' : '' }}">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Projects
        </a>
    </nav>

    <div class="mx-3 my-1 border-t" style="border-color: var(--color-rw-border);"></div>

    <nav class="flex flex-col gap-0.5 p-2">
        <a href="https://coolify.io/docs" target="_blank" rel="noopener" class="rw-nav-item hover:rw-nav-item-hover">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3h11l5 5v13H4z"/><path d="M14 3v5h5"/></svg>
            <span class="flex-1">Docs</span>
            <svg class="w-3 h-3 text-rw-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10"/></svg>
        </a>
        <a href="https://coolify.io" target="_blank" rel="noopener" class="rw-nav-item hover:rw-nav-item-hover">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18M3 12h18"/></svg>
            <span class="flex-1">Central Station</span>
            <svg class="w-3 h-3 text-rw-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17 17 7M7 7h10v10"/></svg>
        </a>
    </nav>

    <div class="flex-1"></div>

    {{-- User --}}
    <div class="flex items-center gap-2.5 p-3 border-t" style="border-color: var(--color-rw-border);">
        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-[10px] font-semibold text-white shrink-0" style="background: linear-gradient(135deg,#5b8def,#8b5cf6);">
            {{ strtoupper(substr($user->name ?? $user->email ?? 'U', 0, 1)) }}
        </span>
        <span class="text-[12px] text-rw-muted truncate flex-1">{{ $user->email }}</span>
        <a href="{{ route('profile') }}" wire:navigate class="rw-icon-btn hover:rw-icon-btn-hover w-7 h-7">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>
        </a>
    </div>
</aside>
