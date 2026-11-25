@php
    $summary = $this->triggerSummary;
    $evaluatedBy = collect([
        $summary['resolvers'] !== [] ? 'by '.implode(' + ', $summary['resolvers']) : null,
        $summary['anchor'] ? 'package '.$summary['anchor'] : null,
    ])->filter()->implode(', ');
@endphp
<div class="flex flex-col gap-3">
    <x-forms.checkbox instantSave id="isSmartTriggersEnabled" label="Smart deploy triggers"
        helper="Deploy on a Git push only when files this application uses change." canGate="update"
        :canResource="$application" />

    <div class="rounded-lg border border-neutral-200 p-3 text-sm dark:border-white/[0.08]">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <span class="font-semibold text-black dark:text-fg">Trigger paths</span>
            @can('update', $application)
                <x-forms.button type="button" wire:click="evaluateNow">Evaluate now</x-forms.button>
            @endcan
        </div>

        @switch($summary['state'])
            @case('watch_paths')
                <p class="text-neutral-600 dark:text-fg-dim"><code>Watch paths</code> override detection.</p>
                @break
            @case('disabled_instance')
                <p class="text-neutral-600 dark:text-fg-dim">Turned off for this instance in <code>Settings</code> &gt; <code>Advanced</code>.</p>
                @break
            @case('disabled')
                <p class="text-neutral-600 dark:text-fg-dim">Every push deploys this application.</p>
                @break
            @case('not_narrowable')
                <p class="text-neutral-600 dark:text-fg-dim">This application builds from the root of the repository, so every push deploys. Set a base directory, or deploy other applications from the same repository, to deploy only on relevant changes.</p>
                @break
            @case('none')
                <p class="text-neutral-600 dark:text-fg-dim">Not evaluated yet. The next push evaluates it.</p>
                @break
            @default
                <ul class="font-mono text-xs text-neutral-800 dark:text-fg">
                    @foreach ($summary['display'] as $line)
                        <li wire:key="trigger-path-{{ md5($line) }}">{{ $line }}</li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-neutral-500 dark:text-fg-faint">
                    Evaluated at <code>{{ substr($summary['evaluatedCommit'], 0, 7) }}</code>{{ $evaluatedBy !== '' ? ' '.$evaluatedBy : '' }}.
                </p>
                @foreach ($summary['notes'] as $note)
                    <p wire:key="trigger-note-{{ md5($note) }}" class="mt-1 text-xs text-neutral-500 dark:text-fg-faint">{{ $note }}</p>
                @endforeach
        @endswitch

        @if ($summary['lastError'])
            <x-callout type="warning" title="Last push" class="mt-3">
                The last push couldn't be evaluated ({{ $summary['lastError'] }}), so it deployed.
            </x-callout>
        @endif
    </div>
</div>
