@props(['resource'])
@php
    $configLink = \App\Services\ProjectConfig\ManagedResources::link($resource);
@endphp
@if ($configLink)
    @php
        $commit = $configLink->lastAppliedRevision?->commit_sha;
        $configUrl = route('project.environment.configuration', ['project_uuid' => $configLink->environment->project->uuid, 'environment_uuid' => $configLink->environment->uuid]);
    @endphp
    <a href="{{ $configUrl }}" {{ wireNavigate() }}
        title="{{ \App\Services\ProjectConfig\ManagedResources::denyReason($resource) }}"
        class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-medium text-coollabs ring-1 ring-coollabs/40 dark:text-warning-300 dark:ring-white/15">
        coolify.json{{ $commit ? ' · '.substr($commit, 0, 7) : '' }}
    </a>
@endif
