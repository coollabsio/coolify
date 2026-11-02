@php
    $build = $selectedBuild;
    $duration = $build->started_at ? $build->started_at->diffForHumans($build->finished_at ?? now(), true) : null;
@endphp

<x-application.settings-section id="cluster-application-build" title="Build"
    helper="{{ $build->git_repository }}">
    <div class="flex flex-wrap items-center gap-3">
        <x-status-badge :label="$build->status->label()" :type="$build->status->badgeType()"
            :spinning="! $build->status->isFinal()" />
        <span class="text-sm text-neutral-600 dark:text-fg-dim">
            {{ $build->source_type->label() }} · {{ $build->git_branch }}
            @if ($build->commit_sha)
                · <span class="font-mono">{{ substr($build->commit_sha, 0, 12) }}</span>
            @endif
            @if ($build->node)
                · on {{ $build->node->name }}
            @endif
            @if ($duration)
                · {{ $duration }}
            @endif
        </span>
        @if (! $build->status->isFinal())
            @can('update', $workload)
                <x-forms.button class="ml-auto" wire:click="cancelBuild" wire:confirm="Cancel this build?">Cancel build</x-forms.button>
            @endcan
        @endif
    </div>
    @if ($build->error)
        <x-callout :type="$build->status === \App\Enums\NodeBuildStatus::CANCELLED ? 'info' : 'danger'" :title="$build->status === \App\Enums\NodeBuildStatus::CANCELLED ? 'Build cancelled' : 'Build failed'" class="mt-4">{{ $build->error }}</x-callout>
    @endif
    @if ($build->image_digest)
        <div class="mt-4">
            <x-forms.copy-input label="Image" :text="\App\Models\NodeRegistry::HOST.'/'.$workload->uuid.'@'.$build->image_digest" />
        </div>
    @endif
</x-application.settings-section>

<x-application.settings-section id="cluster-application-build-log" title="Build log" flush>
    <div class="max-h-[70vh] overflow-auto bg-neutral-950 p-4 font-mono text-xs leading-5 text-neutral-200">
        @forelse ($build->logLines as $line)
            <div wire:key="build-log-{{ $line->id }}" class="whitespace-pre-wrap break-all"><span class="select-none text-neutral-500">{{ $line->logged_at->format('H:i:s') }}</span> {{ $line->line }}</div>
        @empty
            <div class="text-neutral-500">
                {{ $build->status->isFinal() ? 'This build wrote no output.' : 'Waiting for the build to start…' }}
            </div>
        @endforelse
    </div>
</x-application.settings-section>
