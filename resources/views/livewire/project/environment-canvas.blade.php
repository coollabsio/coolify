<div class="h-full">
    <x-slot:title>
        {{ $environment->name }} > Canvas | Coolify
    </x-slot>
    <div data-environment-canvas data-project="{{ $project->uuid }}" data-environment="{{ $environment->uuid }}"
        data-team="{{ $project->team_id }}" data-user="{{ auth()->id() }}"
        data-new="{{ auth()->user()->can('createAnyResource') ? route('deploy-canvas', ['project' => $project->uuid, 'environment' => $environment->uuid]) : '' }}"
        class="relative h-full" wire:ignore>
        {{-- The canvas moves this group into its toolbar; the page actions come last, at the right end. --}}
        <div data-ec-views class="flex items-center gap-2">
            <x-canvas-view-toggle on-canvas
                :list-url="route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid])"
                :canvas-url="route('project.environment.canvas', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid])" />
            <a href="{{ route('shared-variables.environment.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}"
                {{ wireNavigate() }} class="button whitespace-nowrap" title="Shared variables for this environment"
                aria-label="Shared variables for {{ $environment->name }}">
                <x-reicon name="variables" class="size-3.5" />
                Shared variables
            </a>
            @can('update', $project)
                <a href="{{ route('project.environment.edit', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}"
                    {{ wireNavigate() }} class="button whitespace-nowrap" title="Environment settings"
                    aria-label="Open settings for {{ $environment->name }}">
                    <x-reicon name="settings" class="size-3.5" />
                    Settings
                </a>
            @endcan
            @can('createAnyResource')
                {{-- A full page load: the deploy canvas uses the minimal layout. --}}
                <a href="{{ route('deploy-canvas', ['project' => $project->uuid, 'environment' => $environment->uuid]) }}"
                    class="button button-highlighted whitespace-nowrap">
                    <x-reicon name="plus" class="size-3.5" />
                    New resource
                </a>
            @endcan
        </div>
    </div>
</div>
