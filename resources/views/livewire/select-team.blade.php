<x-auth.shell title="Select a team"
    description="Choose the team you want to work in. Your choice is remembered for next time.">
    <div class="flex flex-col gap-2">
        @foreach ($teams as $team)
            <button type="button" wire:click="selectTeam({{ $team->id }})"
                class="group flex items-center gap-3 rounded-lg border border-neutral-200 px-3 py-2.5 text-left transition-colors hover:border-neutral-300 hover:bg-neutral-50 dark:border-white/[0.08] dark:hover:border-white/[0.16] dark:hover:bg-white/[0.04]">
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-[13px] font-semibold text-neutral-600 dark:bg-white/[0.06] dark:text-fg">
                    {{ strtoupper(mb_substr($team->name, 0, 1)) }}
                </span>
                <span class="min-w-0 flex-1 truncate text-[13px] font-semibold text-black dark:text-fg">
                    {{ $team->name }}
                </span>
                <x-reicon name="arrow-right" class="size-4 shrink-0 text-neutral-400 dark:text-fg-faint" />
            </button>
        @endforeach
    </div>
</x-auth.shell>
