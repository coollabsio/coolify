{{--
    Smart Scan result inside the build configuration, as a compact divided list: the build pack,
    the Dockerfile port, and the env file import. The scan button sends the current base directory,
    so the user can scan a subdirectory after changing it.
--}}
@php
    $buildPackLabels = ['dockercompose' => 'Docker Compose', 'dockerfile' => 'Dockerfile'];
    $suggestedFile = match ($suggestedBuildPack) {
        'dockercompose' => $detectedDockerComposeFiles[0] ?? null,
        'dockerfile' => $detectedDockerfiles[0] ?? null,
        default => null,
    };
    $nestedFiles = collect([
        count($detectedDockerfiles) ? count($detectedDockerfiles).' '.Str::plural('Dockerfile', count($detectedDockerfiles)) : null,
        count($detectedDockerComposeFiles) ? count($detectedDockerComposeFiles).' '.Str::plural('Compose file', count($detectedDockerComposeFiles)) : null,
    ])->filter()->join(' and ');
    $showPort = $detectionRan && $build_pack === 'dockerfile' && in_array($selectedDockerfile, $detectedDockerfiles, true);
@endphp

<div class="overflow-hidden rounded-[10px] border border-neutral-200 dark:border-white/[0.08]">
    <div class="flex min-h-10 items-center justify-between gap-3 border-b border-neutral-200 py-1.5 pr-1.5 pl-4 dark:border-white/[0.08]">
        <p class="text-[10px] font-semibold uppercase tracking-[0.08em] text-neutral-400 dark:text-fg-faint">
            Smart Scan
        </p>
        <x-forms.button type="button" wire:click="detectRepository">
            <x-reicon name="search" class="size-3.5" />
            {{ $detectionRan ? 'Scan again' : 'Scan repository' }}
        </x-forms.button>
    </div>

    <div wire:loading.block wire:target="detectRepository">
        <x-checkpoint-item status="running" title="Scanning the repository"
            description="Looking for Dockerfiles, Compose files, and env files." />
    </div>

    <div wire:loading.remove wire:target="detectRepository" class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
        @if (! $detectionRan)
            <x-checkpoint-item icon="search" title="Not scanned yet"
                description="Scan the repository to find Dockerfiles, Compose files, and env files." />
        @elseif ($detectionFailed)
            <x-checkpoint-item status="error" title="Could not read the repository"
                description="Check the repository access and the branch, or select the build pack manually." />
        @elseif ($suggestedBuildPack)
            <x-checkpoint-item status="success"
                title="{{ $buildPackLabels[$suggestedBuildPack] }} {{ $build_pack === $suggestedBuildPack ? 'selected' : 'suggested' }}"
                description="Found {{ $suggestedFile }} in the base directory." />
        @elseif ($nestedFiles !== '')
            <x-checkpoint-item icon="brain" title="Found {{ $nestedFiles }} in subdirectories"
                description="None is in the base directory. To use one, set the base directory to its folder and scan again." />
        @else
            <x-checkpoint-item icon="brain" title="No Dockerfile or Compose file found"
                description="Select the build pack that fits the repository." />
        @endif

        @if ($showPort)
            @if ($detectedPort)
                <x-checkpoint-item status="success" title="Port {{ $detectedPort }}"
                    description="From EXPOSE in {{ $selectedDockerfile }}." />
            @else
                <x-checkpoint-item icon="globe" title="Port {{ $port }}"
                    description="{{ $selectedDockerfile }} has no EXPOSE instruction, so the default port is used." />
            @endif
        @endif

        @if ($detectionRan && count($envExampleVars) > 0)
            <div class="flex items-center justify-between gap-3 pr-4">
                <x-checkpoint-item :status="$envImported ? 'success' : 'idle'" icon="variables"
                    title="{{ $selectedEnvFile }}"
                    description="{{ count($envExampleVars) }} {{ Str::plural('variable', count($envExampleVars)) }}{{ $envImported ? ', imported when you continue' : '' }}." />
                @include('livewire.project.new.partials.env-import-modal')
            </div>
        @endif
    </div>
</div>
