<div>
    <x-slot:title>
        Git Source | Coolify
    </x-slot>

    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="[]" />

    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="sub-menu-wrapper">
            <a class="sub-menu-item" target="_blank" href="{{ $service->documentation() }}"><span class="menu-item-label">Documentation</span>
                <x-external-link /></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.configuration', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">General</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.laravel-manager', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Laravel Manager</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.laravel-artisan', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Artisan Commands</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.laravel-cron', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Laravel Cron</span></a>
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.laravel-git-source', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Git Source</span></a>
        </div>

        <div class="w-full overflow-x-hidden">
            <form class="box-without-bg flex flex-col gap-4" wire:submit="saveGitSource">
                <div class="flex items-center gap-2">
                    <h2>Git Source</h2>
                    <x-forms.button type="submit">Save</x-forms.button>
                    <x-forms.button type="button" wire:click="loadBranches">Detectar ramas</x-forms.button>
                </div>
                <div>Configura repositorio y rama para Laravel RootKit.</div>

                <div class="w-full">
                    <x-forms.input id="repositoryUrl" label="GitHub Repository URL"
                        placeholder="https://github.com/owner/repository"
                        helper="Vinculado con el campo de General." />
                </div>

                <div class="w-full max-w-sm">
                    @if (count($branches) > 0)
                        <x-forms.select id="gitBranch" label="Git Branch"
                            helper="Ramas detectadas del repositorio (sincroniza con General).">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch }}">{{ $branch }}</option>
                            @endforeach
                        </x-forms.select>
                    @else
                        <x-forms.input id="gitBranch" label="Git Branch" placeholder="main"
                            helper="Si no se detectan ramas, puedes escribirla manualmente." />
                    @endif
                </div>

                <div class="w-full max-w-sm">
                    <x-forms.input id="githubToken" type="password" label="GitHub Token"
                        helper="Opcional para repos privados. Se usa para detectar ramas con la API de GitHub." />
                </div>
            </form>
        </div>
    </div>
</div>

