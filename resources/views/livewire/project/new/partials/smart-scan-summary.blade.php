{{--
    Smart Scan result inside the build configuration, as a compact divided list: the build pack,
    the Dockerfile port, and the env file import. The scan button sends the current base directory,
    so the user can scan a subdirectory after changing it. When the scan finds coolify.json, the user
    chooses between the file and the manual settings, and the file card replaces the scan list.
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

@if ($detectedConfigFile)
    <div role="radiogroup" aria-label="How to create the resources" class="grid gap-2 sm:grid-cols-2">
        @foreach ([
            'file' => ['From coolify.json', 'The file defines the resources. Each push to the branch updates them.'],
            'manual' => ['Configure manually', 'Set the build pack and settings below. The file is not used.'],
        ] as $value => [$title, $description])
            <label wire:key="config-source-{{ $value }}" @class([
                'flex cursor-pointer items-start gap-3 rounded-[10px] border px-4 py-3 transition-colors duration-150',
                'border-coollabs bg-coollabs/[0.04] dark:border-brand-light/60 dark:bg-brand-light/[0.06]' => $configSource === $value,
                'border-neutral-200 hover:border-neutral-300 dark:border-white/[0.08] dark:hover:border-white/[0.16]' => $configSource !== $value,
            ])>
                <input type="radio" wire:model.live="configSource" value="{{ $value }}" class="peer sr-only">
                <span @class([
                    'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border transition-colors duration-150 peer-focus-visible:ring-2 peer-focus-visible:ring-coollabs/40',
                    'border-coollabs dark:border-brand-light' => $configSource === $value,
                    'border-neutral-300 dark:border-white/20' => $configSource !== $value,
                ])>
                    @if ($configSource === $value)
                        <span class="size-2 rounded-full bg-coollabs dark:bg-brand-light"></span>
                    @endif
                </span>
                <span class="flex min-w-0 flex-col gap-0.5">
                    <span class="text-[13px] font-medium text-black dark:text-fg">{{ $title }}</span>
                    <span class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $description }}</span>
                </span>
            </label>
        @endforeach
    </div>
@endif

@if ($this->usesConfigFile())
    @php($canLinkConfigFile = $this->canLinkConfigFile())
    <div class="overflow-hidden rounded-[10px] border border-neutral-200 dark:border-white/[0.08]">
        <div class="flex min-h-10 items-center justify-between gap-3 border-b border-neutral-200 py-1.5 pr-1.5 pl-4 dark:border-white/[0.08]">
            <p class="flex min-w-0 items-center gap-2 text-[13px]">
                <x-reicon name="file-content" class="size-3.5 shrink-0 text-neutral-400 dark:text-fg-faint" />
                <code class="truncate font-medium text-black dark:text-fg">{{ $configPlan['link']['file_path'] ?? $detectedConfigFile }}</code>
            </p>
            <x-forms.button type="button" wire:click="detectRepository">
                <x-reicon name="search" class="size-3.5" />
                Scan again
            </x-forms.button>
        </div>

        <div wire:loading.block wire:target="detectRepository, previewConfigFile, configSource">
            <x-checkpoint-item status="running" title="Reading coolify.json"
                description="Coolify reads the file and plans the resources for this environment." />
        </div>

        <div wire:loading.remove wire:target="detectRepository, previewConfigFile, configSource">
            @if ($configPlanError)
                <x-checkpoint-item status="error" title="Could not plan the file" description="{{ $configPlanError }}" />
            @elseif ($configPlan && ! empty($configPlan['errors']))
                <x-checkpoint-item status="error" title="The file has {{ count($configPlan['errors']) }} {{ Str::plural('error', count($configPlan['errors'])) }}"
                    description="Fix the file on the branch, then scan again." />
                <ul class="space-y-1 border-t border-neutral-200 px-4 py-3 text-[12px] dark:border-white/[0.06]">
                    @foreach ($configPlan['errors'] as $error)
                        <li><code class="text-red-600 dark:text-red-400">{{ $error['path'] ?: '(file)' }}</code> {{ $error['message'] }}</li>
                    @endforeach
                </ul>
            @elseif ($configPlan)
                <p class="px-4 pt-3 pb-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                    Creating this environment from the file adds:
                </p>
                <x-project-config.plan-groups :changes="$configPlan['changes'] ?? []" compact :values="$canLinkConfigFile" />
            @else
                <x-checkpoint-item icon="file-content" title="Plan not loaded"
                    description="Show the plan to see the resources that the file creates." />
            @endif
        </div>

        @if ($configPlan && empty($configPlan['errors']))
            <div class="border-t border-neutral-200 px-4 py-3 text-[12px] text-neutral-500 dark:border-white/[0.06] dark:text-fg-dim">
                Anyone who can push to <code>{{ $configPlan['link']['branch'] }}</code> of
                <code>{{ $configPlan['link']['repository'] }}</code> can change these resources and use the team's shared variables.
                @unless ($canLinkConfigFile)
                    <span class="mt-1 block text-amber-600 dark:text-amber-400">Only a team admin can link coolify.json. Ask an admin, or configure it manually.</span>
                @endunless
            </div>
        @endif
    </div>
@else
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
                <x-checkpoint-item icon="brain" title="Found {{ $nestedFiles }} in subdirectories" />
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
@endif
