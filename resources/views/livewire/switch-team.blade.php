@php
    $currentTeam = auth()->user()->currentTeam();
    $teamInitial = strtoupper(mb_substr($currentTeam->name, 0, 1));
@endphp
<div class="min-w-0">
    {{-- Expanded: inline switcher (team name + up/down chevron) --}}
    <div class="relative min-w-0" :class="collapsed && 'lg:hidden'"
        x-data="{ open: false }" @keydown.escape.window="open = false">
        <button type="button" @click="open = !open" @click.outside="open = false"
            title="Switch team"
            class="group/team flex h-8 items-center gap-1.5 rounded-lg px-2 -ml-1 text-left opacity-70 transition-[background-color,opacity] hover:bg-neutral-100 hover:opacity-100 dark:hover:bg-white/[0.05]">
            <span class="whitespace-nowrap text-[13px] font-semibold text-black dark:text-fg">{{ $currentTeam->name }}</span>
            <svg class="size-4 shrink-0 text-neutral-400 dark:text-fg-faint" viewBox="0 0 24 24" fill="none">
                <path d="M8 9l4-4 4 4M8 15l4 4 4-4" stroke="currentColor" stroke-width="1.6"
                    stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
        <div x-show="open" x-cloak x-transition.opacity.duration.120ms
            class="listbox-panel left-0! z-[90]! max-h-72! min-w-56">
            <div
                class="px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-neutral-400 dark:text-fg-faint">
                Teams
            </div>
            @foreach (auth()->user()->teams as $team)
                <button type="button" wire:click="switch_to({{ $team->id }}, window.location.href)" @click="open = false"
                    class="listbox-option {{ $team->id === $currentTeam->id ? 'bg-neutral-100 font-medium dark:bg-white/[0.06]' : '' }}">
                    <span class="min-w-0 flex-1 truncate">{{ $team->name }}</span>
                </button>
            @endforeach
            <div class="mt-1 border-t border-neutral-200 pt-1 dark:border-white/[0.08]">
                <x-modal-input title="New Team">
                    <x-slot:content>
                        <button type="button" class="listbox-option w-full" @click="open = false">
                            <x-reicon name="plus" class="size-3.5 shrink-0" />
                            <span class="min-w-0 flex-1 text-left">New team</span>
                        </button>
                    </x-slot:content>
                    <livewire:team.create :key="'team-switcher-create-expanded'" />
                </x-modal-input>
            </div>
        </div>
    </div>

    {{-- Collapsed: square initial with flyout menu --}}
    <div class="hidden" :class="collapsed && 'lg:block'"
        x-data="{
            teamOpen: false,
            teamX: 0,
            teamY: 0,
            openTeamMenu(ev) {
                const rect = ev.currentTarget.getBoundingClientRect();
                this.teamX = rect.right + 8;
                this.teamY = rect.top;
                this.teamOpen = !this.teamOpen;
            }
        }">
        <button @click="openTeamMenu($event)" type="button" title="Team: {{ $currentTeam->name }}"
            class="flex items-center justify-center w-8 h-8 p-0 text-[13px] font-semibold text-neutral-600 dark:text-fg bg-neutral-100 dark:bg-white/[0.06] hover:bg-neutral-200 dark:hover:bg-white/[0.1] rounded-lg cursor-pointer transition-colors">
            {{ $teamInitial }}
        </button>
        <div x-show="teamOpen" @click.outside="teamOpen = false" x-transition.opacity.duration.100ms x-cloak
            :style="`left: ${teamX}px; top: ${teamY}px;`"
            class="listbox-panel fixed! top-auto! z-[100]! max-h-72! min-w-48">
            <div
                class="px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-neutral-400 dark:text-fg-faint">
                Teams
            </div>
            @foreach (auth()->user()->teams as $team)
                <button type="button" wire:click="switch_to({{ $team->id }}, window.location.href)" @click="teamOpen = false"
                    class="listbox-option {{ $team->id === $currentTeam->id ? 'bg-neutral-100 font-medium dark:bg-white/[0.06]' : '' }}">
                    <span class="min-w-0 flex-1 truncate">{{ $team->name }}</span>
                </button>
            @endforeach
            <div class="mt-1 border-t border-neutral-200 pt-1 dark:border-white/[0.08]">
                <x-modal-input title="New Team">
                    <x-slot:content>
                        <button type="button" class="listbox-option w-full" @click="teamOpen = false">
                            <x-reicon name="plus" class="size-3.5 shrink-0" />
                            <span class="min-w-0 flex-1 text-left">New team</span>
                        </button>
                    </x-slot:content>
                    <livewire:team.create :key="'team-switcher-create-collapsed'" />
                </x-modal-input>
            </div>
        </div>
    </div>
</div>
