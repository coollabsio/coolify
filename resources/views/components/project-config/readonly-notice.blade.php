@props(['resource'])
@php
    $configLink = \App\Services\ProjectConfig\ManagedResources::link($resource);
@endphp
@if ($configLink)
    <p {{ $attributes->class('flex items-center gap-1.5 text-[12px] text-neutral-500 dark:text-fg-dim') }}>
        <x-reicon name="file-content" class="size-3.5 shrink-0" />
        Read-only: these settings come from coolify.json{{ $configLink->isGit() ? ' on '.$configLink->branch : '' }}.
        <a href="{{ route('project.environment.configuration', ['project_uuid' => $configLink->environment->project->uuid, 'environment_uuid' => $configLink->environment->uuid]) }}"
            @if (wireNavigate()) wire:navigate @endif class="font-medium text-neutral-700 underline-offset-2 hover:underline dark:text-fg">Open Config as code</a>
    </p>
@endif
