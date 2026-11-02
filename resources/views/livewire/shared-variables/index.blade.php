<div>
    <x-slot:title>
        Shared Variables | Coolify
    </x-slot>

    @php
        $scopeCards = [
            'team' => ['Team wide', 'teams', route('shared-variables.team.index'), 'Every resource of this team.', null],
            'project' => ['Project wide', 'projects', route('shared-variables.project.index'), 'Every environment of a project.', 'project'],
            'environment' => ['Environment wide', 'layers', route('shared-variables.environment.index'), 'Resources in one environment.', 'environment'],
            'server' => ['Server wide', 'servers', route('shared-variables.server.index'), 'Resources deployed on one server.', 'server'],
        ];
        $panel = 'rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]';
        $panelTitle = 'flex items-center justify-between gap-2 border-b border-neutral-200 px-3 py-2.5 text-[12px] font-semibold text-black dark:border-white/[0.06] dark:text-fg';
        $row = 'flex items-center gap-2 border-b border-neutral-100 px-3 py-2 last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.04] dark:hover:bg-white/[0.03]';
        $scopeBadge = 'shrink-0 rounded-full border border-neutral-200 bg-neutral-50 px-1.5 text-[10px] leading-4 font-medium text-neutral-500 dark:border-white/[0.1] dark:bg-white/[0.05] dark:text-fg-dim';
    @endphp

    <x-shared-variables.layout>
        <div class="flex w-full flex-col gap-4">
            {{-- Scopes, with how many variables each has and on how many owners. --}}
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($scopeCards as $scope => [$label, $icon, $href, $description, $ownerNoun])
                    @php $counts = $overview['scopes'][$scope]; @endphp
                    <a class="group flex min-h-32 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.05] dark:hover:border-white/[0.14]"
                        href="{{ $href }}" {{ wireNavigate() }}>
                        <div class="flex items-start justify-between gap-2">
                            <div
                                class="flex size-8 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                <x-reicon :name="$icon" class="size-4" />
                            </div>
                            <span class="text-[22px] leading-7 font-semibold tabular-nums text-black dark:text-fg">{{ $counts['variables'] }}</span>
                        </div>
                        <div class="mt-auto pt-5">
                            <h2 class="text-[13px]! leading-4! font-semibold! text-black dark:text-fg">{{ $label }}</h2>
                            <p class="mt-1 text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">
                                @if ($ownerNoun && $counts['variables'] > 0)
                                    {{ str('variable')->plural($counts['variables']) }} on {{ $counts['owners'] }} {{ str($ownerNoun)->plural($counts['owners']) }}.
                                @else
                                    {{ $description }}
                                @endif
                            </p>
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($overview['missing'] > 0)
                <div class="flex items-start gap-2 rounded-xl border border-warning/30 bg-warning/10 px-3 py-2.5 text-[12px] text-neutral-700 dark:text-fg-dim" role="note">
                    <x-reicon name="alert-triangle" class="mt-px size-4 shrink-0 text-warning" />
                    <p>
                        {{ $overview['missing'] }} {{ str('reference')->plural($overview['missing']) }} in resource variables
                        {{ $overview['missing'] === 1 ? 'points' : 'point' }} to a shared variable that does not exist. Open a scope canvas to find
                        {{ $overview['missing'] === 1 ? 'it' : 'them' }}.
                    </p>
                </div>
            @endif

            <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                {{-- Most used: links open the variable on its scope canvas. --}}
                <section class="{{ $panel }}">
                    <h2 class="{{ $panelTitle }}">Most used</h2>
                    @forelse ($overview['used'] as $variable)
                        <a class="{{ $row }}" href="{{ $variable['link'] }}" {{ wireNavigate() }}>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-mono text-[12px] text-black dark:text-fg">{{ $variable['key'] }}</span>
                                <span class="block truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $variable['owner'] }}</span>
                            </span>
                            <span class="shrink-0 text-[11px] text-neutral-500 tabular-nums dark:text-fg-dim">
                                {{ $variable['resources'] }} {{ str('resource')->plural($variable['resources']) }}
                            </span>
                        </a>
                    @empty
                        <p class="px-3 py-4 text-[12px] text-neutral-500 dark:text-fg-faint">No resource uses a shared variable yet.</p>
                    @endforelse
                </section>

                <section class="{{ $panel }}">
                    <h2 class="{{ $panelTitle }}">Recently changed</h2>
                    @forelse ($overview['recent'] as $variable)
                        <a class="{{ $row }}" href="{{ $variable['link'] }}" {{ wireNavigate() }}>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-mono text-[12px] text-black dark:text-fg">{{ $variable['key'] }}</span>
                                <span class="block truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $variable['owner'] }}</span>
                            </span>
                            <span class="shrink-0 text-[11px] text-neutral-500 dark:text-fg-dim">{{ $variable['updated_at']?->diffForHumans(short: true) }}</span>
                        </a>
                    @empty
                        <p class="px-3 py-4 text-[12px] text-neutral-500 dark:text-fg-faint">No shared variables yet.</p>
                    @endforelse
                </section>

                {{-- Not referenced by any resource: candidates to use or to clean up. --}}
                <section class="{{ $panel }}">
                    <h2 class="{{ $panelTitle }}">
                        Not used
                        <span class="text-[11px] font-normal text-neutral-400 tabular-nums dark:text-fg-faint">{{ $overview['unused']->count() }}</span>
                    </h2>
                    <div class="max-h-72 overflow-y-auto">
                        @forelse ($overview['unused'] as $variable)
                            <a class="{{ $row }}" href="{{ $variable['link'] }}" {{ wireNavigate() }}>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-mono text-[12px] text-black dark:text-fg">{{ $variable['key'] }}</span>
                                    <span class="block truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $variable['owner'] }}</span>
                                </span>
                                <span class="{{ $scopeBadge }}">{{ str($variable['scope'])->headline() }}</span>
                            </a>
                        @empty
                            <p class="px-3 py-4 text-[12px] text-neutral-500 dark:text-fg-faint">Every shared variable is in use.</p>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>
    </x-shared-variables.layout>
</div>
