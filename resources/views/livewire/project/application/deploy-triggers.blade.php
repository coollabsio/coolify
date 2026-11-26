@php
    $summary = $this->triggerSummary;
    $state = $isEvaluating ? 'evaluating' : $summary['state'];
    $pathCount = collect($summary['display'])->reject(fn ($line) => $line['excluded'])->count();
    $canEvaluate = in_array($summary['state'], ['none', 'evaluated'], true);
    $title = match ($state) {
        'evaluating' => 'Evaluating the deployed commit',
        'evaluated' => $pathCount.' '.Str::plural('path', $pathCount).' '.($pathCount === 1 ? 'triggers' : 'trigger').' a deploy',
        'none' => 'Not evaluated yet',
        'watch_paths' => 'Watch paths decide which pushes deploy',
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
        'evaluated', 'evaluating' => 'filter',
        'none' => 'time-back',
        default => 'webhook',
    };
@endphp
<div class="flex flex-col gap-3" @if ($isEvaluating) wire:poll.3s="checkEvaluation" @endif>
    <div class="w-full sm:w-96">
        <x-forms.checkbox instantSave id="isSmartTriggersEnabled" label="Smart deploy triggers"
            helper="Deploy on a Git push only when files this application uses change. Pushes that change none of them show as skipped in the deployment list."
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
                            Clear <code>Watch paths</code> to let Coolify find the files this application uses.
                            @break
                        @case('disabled_instance')
                            An instance admin can turn it on in <code>Settings</code> &gt; <code>Advanced</code>.
                            @break
                        @case('not_narrowable')
                            This application builds from the root of the repository. Set a base directory, or deploy other applications from the same repository, to deploy only on relevant changes.
                            @break
                        @default
                            Turn on <code>Smart deploy triggers</code> to skip pushes that change no files this application uses.
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

        @if ($summary['lastError'])
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

        @if ($summary['state'] === 'evaluated' && $summary['groups'] !== [])
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
