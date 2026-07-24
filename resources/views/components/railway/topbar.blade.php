@props([
    'project',
    'environment',
    'projects' => null,
    'environments' => null,
])

@php
    $projects = $projects ?? collect();
    $environments = $environments ?? collect();
    $team = currentTeam();
    $defaultEnvUuid = function ($p) {
        $envs = $p->environments;
        return optional($envs->firstWhere('name', 'production') ?? $envs->first())->uuid;
    };
@endphp

<header class="relative z-40 flex items-center gap-1 h-14 px-3 border-b" style="border-color: var(--color-rw-border); background: var(--color-rw-surface);">
    {{-- Brand --}}
    <a href="{{ route('railway.projects') }}" wire:navigate class="rw-icon-btn hover:rw-icon-btn-hover mr-1">
        <x-railway.logo size="w-5 h-5" />
    </a>

    {{-- Project switcher --}}
    <div class="relative" x-data="{ open: false }">
        <button type="button" @click="open = !open" @click.outside="open = false"
            class="flex items-center gap-2 rounded-md px-2 h-8 text-[13px] font-medium text-rw-text hover:bg-rw-hover">
            <span class="inline-flex items-center justify-center w-4 h-4 rounded-full text-[9px]"
                style="background: linear-gradient(135deg,#8b5cf6,#e5484d);">&nbsp;</span>
            <span class="max-w-[180px] truncate">{{ $project->name }}</span>
            <svg class="w-3.5 h-3.5 text-rw-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div x-show="open" x-cloak x-transition.origin.top.left class="absolute left-0 top-10 w-64 rw-menu">
            <div class="px-2.5 py-1.5 text-[11px] font-semibold text-rw-subtle uppercase tracking-wide flex items-center gap-2">
                <span class="inline-block w-4 h-4 rounded-full" style="background: linear-gradient(135deg,#8b5cf6,#e5484d);"></span>
                {{ $team->name }}
            </div>
            <div class="max-h-72 overflow-y-auto scrollbar">
                @foreach ($projects as $p)
                    @php $envUuid = $defaultEnvUuid($p); @endphp
                    @if ($envUuid)
                        <a href="{{ route('railway.canvas', ['project_uuid' => $p->uuid, 'environment_uuid' => $envUuid]) }}" wire:navigate
                            class="rw-menu-item hover:rw-menu-item-hover">
                            <svg class="w-3.5 h-3.5 {{ $p->uuid === $project->uuid ? 'text-rw-accent' : 'text-transparent' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m5 12 5 5L20 7"/></svg>
                            <span class="truncate">{{ $p->name }}</span>
                        </a>
                    @endif
                @endforeach
            </div>
            <div class="mt-1 pt-1 border-t" style="border-color: var(--color-rw-border);">
                <a href="{{ route('project.index') }}" wire:navigate class="rw-menu-item hover:rw-menu-item-hover text-rw-accent">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    New Project
                </a>
            </div>
        </div>
    </div>

    <span class="text-rw-subtle">/</span>

    {{-- Environment switcher --}}
    <div class="relative" x-data="{ open: false }">
        <button type="button" @click="open = !open" @click.outside="open = false"
            class="flex items-center gap-2 rounded-md px-2 h-8 text-[13px] font-medium text-rw-text hover:bg-rw-hover">
            <span class="max-w-[180px] truncate">{{ $environment->name }}</span>
            <svg class="w-3.5 h-3.5 text-rw-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div x-show="open" x-cloak x-transition.origin.top.left class="absolute left-0 top-10 w-60 rw-menu">
            <div class="px-2.5 py-1.5 text-[11px] font-semibold text-rw-subtle uppercase tracking-wide">Environments</div>
            <div class="max-h-72 overflow-y-auto scrollbar">
                @foreach ($environments as $env)
                    <a href="{{ route('railway.canvas', ['project_uuid' => $project->uuid, 'environment_uuid' => $env->uuid]) }}" wire:navigate
                        class="rw-menu-item hover:rw-menu-item-hover">
                        <svg class="w-3.5 h-3.5 {{ $env->uuid === $environment->uuid ? 'text-rw-accent' : 'text-transparent' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m5 12 5 5L20 7"/></svg>
                        <span class="truncate">{{ $env->name }}</span>
                    </a>
                @endforeach
            </div>
            <div class="mt-1 pt-1 border-t" style="border-color: var(--color-rw-border);">
                <a href="{{ route('project.environment.edit', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}" wire:navigate
                    class="rw-menu-item hover:rw-menu-item-hover text-rw-accent">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    New Environment
                </a>
            </div>
        </div>
    </div>

    <div class="flex-1"></div>

    {{-- Right-side controls --}}
    <a href="{{ route('railway.observability', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}" wire:navigate
        class="rw-icon-btn hover:rw-icon-btn-hover" title="Observability">
        <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3 8 4-16 3 8h4"/></svg>
    </a>
    <button type="button" class="rw-icon-btn hover:rw-icon-btn-hover" title="Notifications">
        <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
    </button>
    <div class="mx-1 w-px h-5" style="background: var(--color-rw-border);"></div>
    <button type="button" class="rw-btn hover:rw-btn-hover" title="Agent">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 12h8M8 8h8M8 16h5"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
        Agent
    </button>
</header>
