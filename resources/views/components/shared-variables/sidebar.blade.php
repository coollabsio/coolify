@php
    $sharedVariablesMenuItems = [
        ['label' => 'Overview', 'route' => 'shared-variables.index', 'icon' => 'dashboard', 'active' => request()->routeIs('shared-variables.index')],
        ['label' => 'Team', 'route' => 'shared-variables.team.index', 'icon' => 'teams', 'active' => request()->routeIs('shared-variables.team.*')],
        ['label' => 'Projects', 'route' => 'shared-variables.project.index', 'icon' => 'projects', 'active' => request()->routeIs('shared-variables.project.*')],
        ['label' => 'Environments', 'route' => 'shared-variables.environment.index', 'icon' => 'layers', 'active' => request()->routeIs('shared-variables.environment.*')],
        ['label' => 'Servers', 'route' => 'shared-variables.server.index', 'icon' => 'servers', 'active' => request()->routeIs('shared-variables.server.*')],
    ];
@endphp

{{-- The pages are in the Shared Variables submenu of the main sidebar (components/navbar.blade.php);
     this only feeds the page search. --}}
<div hidden data-settings-search-items="{{ json_encode(settingsSearchItems(['Shared variables' => $sharedVariablesMenuItems], [])) }}"></div>
