@php
    $currentTeam = auth()->user()->currentTeam();
    $teamInitial = strtoupper(mb_substr($currentTeam->name, 0, 1));
@endphp
<div>
    <div :class="collapsed && 'lg:hidden'">
        <x-forms.select wire:model.live="selectedTeamId">
            <option value="default" disabled selected>Switch team</option>
            @foreach (auth()->user()->teams as $team)
                <option value="{{ $team->id }}">{{ $team->name }}</option>
            @endforeach
        </x-forms.select>
    </div>
    <div class="hidden"
        :class="collapsed && 'lg:block'"
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
        <button @click="openTeamMenu($event)" type="button"
            title="Team: {{ $currentTeam->name }}"
            class="flex items-center justify-center w-8 h-8 p-0 text-sm font-semibold text-coollabs dark:text-warning bg-neutral-100 dark:bg-coolgray-200 hover:bg-neutral-200 dark:hover:bg-coolgray-300 rounded-sm cursor-pointer transition-colors">
            {{ $teamInitial }}
        </button>
        <div x-show="teamOpen"
            @click.outside="teamOpen = false"
            x-transition.opacity.duration.100ms
            x-cloak
            :style="`left: ${teamX}px; top: ${teamY}px;`"
            class="fixed z-[100] min-w-48 max-h-72 overflow-y-auto bg-white dark:bg-coolgray-100 border border-neutral-300 dark:border-coolgray-200 rounded-md shadow-lg py-1">
            <div class="px-3 py-1.5 text-xs font-semibold text-neutral-500 dark:text-neutral-400 border-b border-neutral-200 dark:border-coolgray-200">Switch team</div>
            @foreach (auth()->user()->teams as $team)
                <button type="button"
                    wire:click="switch_to({{ $team->id }})"
                    @click="teamOpen = false"
                    class="w-full px-3 py-1.5 text-left text-sm hover:bg-neutral-100 dark:hover:bg-coolgray-200 dark:text-white {{ $team->id === $currentTeam->id ? 'font-semibold text-coollabs dark:text-warning' : '' }}">
                    {{ $team->name }}
                </button>
            @endforeach
        </div>
    </div>
</div>
