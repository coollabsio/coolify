@php
    $summary = $this->triggerSummary;
    $state = match (true) {
        $isEvaluating => 'evaluating',
        $triggerMode === 'watch_paths' && $summary['state'] !== 'watch_paths' => 'watch_paths_pending',
        default => $summary['state'],
    };
    $smartAllowed = \App\Livewire\Project\Application\DeployTriggers::isSmartAllowedOnInstance();
    $modeOptions = [
        ['value' => 'all', 'label' => 'Every push', 'description' => 'Each push to the branch deploys.'],
        ['value' => 'smart', 'label' => 'Smart deploy triggers', 'description' => $smartAllowed ? 'Only pushes that change files this application uses deploy.' : 'Turned off for this instance.', 'disabled' => ! $smartAllowed && $triggerMode !== 'smart'],
        ['value' => 'watch_paths', 'label' => 'Watch paths', 'description' => 'Only pushes that change files matching your patterns deploy.'],
    ];
    $pathCount = collect($summary['display'])->reject(fn ($line) => $line['excluded'])->count();
    $canEvaluate = $triggerMode === 'smart' && in_array($summary['state'], ['none', 'evaluated'], true);
    $title = match ($state) {
        'evaluating' => 'Evaluating the deployed commit',
        'evaluated' => $pathCount.' '.Str::plural('path', $pathCount).' '.($pathCount === 1 ? 'triggers' : 'trigger').' a deploy',
        'none' => 'Not evaluated yet',
        'watch_paths' => 'Watch paths decide which pushes deploy',
        'watch_paths_pending' => 'No watch paths yet',
        'disabled_instance' => 'Turned off for this instance',
        default => 'Every push deploys',
    };
    $mono = fn (string $value): string => '<span class="font-mono text-neutral-700 dark:text-fg">'.e($value).'</span>';
    $evaluatedDetail = $summary['evaluatedCommit'] === null ? '' : collect([
        'Evaluated at '.$mono(substr($summary['evaluatedCommit'], 0, 7)),
        $summary['resolvers'] !== [] ? 'with '.e(implode(' + ', $summary['resolvers'])) : null,
        $summary['anchor'] ? 'for '.$mono($summary['anchor']) : null,
    ])->filter()->implode(' ').'. Other pushes are skipped.';
    $icon = match ($state) {
        'evaluated', 'evaluating', 'watch_paths' => 'filter',
        'none', 'watch_paths_pending' => 'time-back',
        default => 'webhook',
    };
@endphp
<div class="flex flex-col gap-3" @if ($isEvaluating) wire:poll.3s="checkEvaluation" @endif>
    <div class="w-full sm:w-96">
        <x-forms.listbox id="triggerMode" label="Deploy on" onChange="setTriggerMode" :options="$modeOptions"
            helper="Pushes that don't deploy show as skipped in the deployment list, where you can deploy them anyway."
            canGate="update" :canResource="$application" />
    </div>

    <div class="rounded-lg border border-neutral-200 bg-neutral-50/60 dark:border-white/[0.07] dark:bg-white/[0.05]">
        <div class="flex flex-wrap items-center gap-3 px-4 py-3">
            <div class="flex size-9 shrink-0 items-center justify-center rounded-md bg-neutral-200/70 text-neutral-600 dark:bg-white/[0.07] dark:text-fg-dim">
                @if ($state === 'evaluating')
                    <x-reicon name="refresh" class="size-4 animate-spin motion-reduce:animate-none" />
                @else
                    <x-reicon :name="$icon" class="size-4" />
                @endif
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-black dark:text-fg">{{ $title }}</p>
                <p class="text-xs text-neutral-500 dark:text-fg-dim">
                    @switch($state)
                        @case('evaluating')
                            The trigger paths update in a moment.
                            @break
                        @case('evaluated')
                            {!! $evaluatedDetail !!}
                            @break
                        @case('none')
                            The next push finds the files this application uses.
                            @break
                        @case('watch_paths')
                            Only pushes that change a file matching these patterns deploy.
                            @break
                        @case('watch_paths_pending')
                            Add patterns and save them. Until then, {{ in_array($summary['state'], ['none', 'evaluated'], true) ? 'smart deploy triggers decide which pushes deploy.' : 'every push deploys.' }}
                            @break
                        @case('disabled_instance')
                            An instance admin can turn it on in <code>Settings</code> &gt; <code>Advanced</code>.
                            @break
                        @case('not_narrowable')
                            This application builds from the root of the repository. Set a base directory, or deploy other applications from the same repository, to deploy only on relevant changes.
                            @break
                        @default
                            Each push to <code>{{ $application->git_branch }}</code> deploys this application.
                    @endswitch
                </p>
            </div>
            @if ($canEvaluate)
                @can('update', $application)
                    <x-forms.button type="button" wire:click="evaluateNow" class="ml-auto shrink-0" :disabled="$isEvaluating">
                        Evaluate now
                    </x-forms.button>
                @endcan
            @endif
        </div>

        @if ($summary['lastError'] && $state !== 'watch_paths_pending')
            <div class="px-4 pb-3">
                @if ($summary['lastErrorFromEvaluateNow'])
                    <x-callout type="warning" title="Evaluate now">
                        Evaluate now could not finish ({{ $summary['lastError'] }}).
                    </x-callout>
                @else
                    <x-callout type="warning" title="Last push">
                        The last push couldn't be evaluated ({{ $summary['lastError'] }}), so it deployed.
                    </x-callout>
                @endif
            </div>
        @endif

        @if ($triggerMode === 'watch_paths')
            <div class="flex flex-col gap-3 border-t border-neutral-200 px-4 py-3 dark:border-white/[0.07]">
                <x-forms.textarea id="watchPaths" label="Watch paths" rows="4" monospace
                    helper="Enter one pattern per line. Commas do not separate patterns. Supports wildcards (*, **, ?) and negation (!). The last matching pattern wins."
                    placeholder="{{ $application->build_pack === 'dockercompose' ? 'services/api/**' : 'src/pages/**' }}"
                    canGate="update" :canResource="$application" />
                @can('update', $application)
                    <div>
                        <x-forms.button type="button" wire:click="saveWatchPaths">Save watch paths</x-forms.button>
                    </div>
                @endcan
            </div>
        @endif

        @if ($summary['state'] === 'evaluated' && $triggerMode === 'smart' && $summary['groups'] !== [])
            <dl class="divide-y divide-neutral-200 border-t border-neutral-200 dark:divide-white/[0.07] dark:border-white/[0.07]">
                @foreach ($summary['groups'] as $group)
                    <div wire:key="trigger-group-{{ md5($group['label']) }}" class="grid gap-x-6 gap-y-1.5 px-4 py-3 sm:grid-cols-[10rem_minmax(0,1fr)]">
                        <dt class="flex items-baseline gap-1.5 text-xs font-medium text-neutral-600 dark:text-fg-dim">
                            {{ $group['label'] }}
                            <span class="font-normal tabular-nums text-neutral-400 dark:text-fg-faint">{{ count($group['paths']) }}</span>
                        </dt>
                        <dd class="grid grid-cols-[repeat(auto-fit,minmax(16rem,1fr))] gap-x-6 gap-y-1">
                            @foreach ($group['paths'] as $path)
                                <span wire:key="trigger-path-{{ md5($group['label'].$path) }}" @class([
                                    'font-mono text-xs [overflow-wrap:anywhere]',
                                    'text-neutral-800 dark:text-fg' => $group['label'] !== 'Ignored',
                                    'text-neutral-500 dark:text-fg-faint' => $group['label'] === 'Ignored',
                                ])>{{ $path }}</span>
                            @endforeach
                        </dd>
                    </div>
                @endforeach
            </dl>
            @if ($summary['notes'] !== [])
                <ul class="flex flex-col gap-1 border-t border-neutral-200 px-4 py-3 text-xs text-neutral-500 dark:border-white/[0.07] dark:text-fg-dim">
                    @foreach ($summary['notes'] as $note)
                        <li wire:key="trigger-note-{{ md5($note) }}" class="flex items-start gap-1.5">
                            <x-reicon name="info-circle" class="mt-px size-3.5 shrink-0" />
                            <span>{{ $note }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </div>
</div>
