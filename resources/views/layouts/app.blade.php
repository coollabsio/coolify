@extends('layouts.base')
@section('body')
    @parent
    @if (isSubscribed() || !isCloud())
        <livewire:layout-popups />
    @endif
    <livewire:global-search />
    @auth
        @php
            // Resolve the project/environment context from the current route so classic
            // pages render inside the Railway chrome (topbar + rail) instead of the old
            // Coolify sidebar. Non-project pages fall back to the Railway global sidebar.
            $rwProject = null;
            $rwEnvironment = null;
            $rwAllProjects = null;
            $rwAllEnvironments = null;
            $rwProjectUuid = request()->route('project_uuid');
            $rwEnvUuid = request()->route('environment_uuid');

            if ($rwProjectUuid && function_exists('currentTeam') && currentTeam()) {
                $rwProject = currentTeam()->projects()->where('uuid', $rwProjectUuid)->first();
                if ($rwProject) {
                    $rwAllProjects = \App\Models\Project::ownedByCurrentTeamCached();
                    $rwAllEnvironments = $rwProject->environments()->select('id', 'uuid', 'name', 'project_id')->get();
                    if ($rwEnvUuid) {
                        $rwEnvironment = $rwAllEnvironments->firstWhere('uuid', $rwEnvUuid);
                    }
                    $rwEnvironment = $rwEnvironment
                        ?? $rwAllEnvironments->firstWhere('name', 'production')
                        ?? $rwAllEnvironments->first();
                }
            }

            $rwActive = 'architecture';
            if (request()->routeIs('*.edit') || request()->routeIs('*settings*') || request()->routeIs('*.danger*')) {
                $rwActive = 'settings';
            } elseif (request()->routeIs('*.logs')) {
                $rwActive = 'logs';
            } elseif (request()->routeIs('*.metrics') || request()->routeIs('*observability*')) {
                $rwActive = 'observability';
            }
        @endphp

        {{-- collapsed/pageWidth/sidebarReady kept so classic page content that references
             the old layout's Alpine state keeps working inside the Railway chrome. --}}
        <div class="rw-root" x-data="{ open: false, collapsed: false, pageWidth: 'full', sidebarReady: true }" x-cloak>
            @if ($rwProject && $rwEnvironment)
                <x-railway.project-chrome :project="$rwProject" :environment="$rwEnvironment"
                    :projects="$rwAllProjects" :environments="$rwAllEnvironments" :active="$rwActive">
                    <div class="h-full overflow-y-auto scrollbar">
                        <div class="max-w-6xl mx-auto px-8 py-6">
                            {{ $slot }}
                        </div>
                    </div>
                </x-railway.project-chrome>
            @else
                <div class="flex w-full">
                    <x-railway.app-nav />
                    <main class="flex-1 min-w-0 h-screen overflow-y-auto scrollbar">
                        <div class="max-w-6xl mx-auto px-8 py-6">
                            {{ $slot }}
                        </div>
                    </main>
                </div>
            @endif
        </div>
    @endauth
@endsection
