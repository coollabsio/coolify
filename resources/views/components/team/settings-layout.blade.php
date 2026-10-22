@php
    $teamMenuItems = collect([
        [
            'label' => 'General',
            'route' => 'team.index',
            'active' => request()->routeIs('team.index'),
            'icon' => 'settings',
        ],
        [
            'label' => 'Members',
            'route' => 'team.member.index',
            'active' => request()->routeIs('team.member.index'),
            'icon' => 'teams',
        ],
        auth()->user()->isAdminOfTeam(currentTeam()->id) ? [
            'label' => 'Audit log',
            'route' => 'team.audit-log',
            'active' => request()->routeIs('team.audit-log'),
            'icon' => 'time-back',
        ] : null,
        isInstanceAdmin() ? [
            'label' => 'Admin View',
            'route' => 'team.admin-view',
            'active' => request()->routeIs('team.admin-view'),
            'icon' => 'admin',
        ] : null,
        auth()->user()->can('delete', currentTeam()) ? [
            'label' => 'Danger Zone',
            'route' => 'team.danger-zone',
            'active' => request()->routeIs('team.danger-zone'),
            'icon' => 'danger',
            'sectionStart' => true,
        ] : null,
    ])->filter();
@endphp

{{-- The team pages are in the Team submenu of the main sidebar (components/navbar.blade.php). --}}
<section class="w-full max-w-none">
    <header class="settings-mobile-header xl:hidden">
        <h1 class="settings-mobile-title">Team</h1>
        <p class="settings-mobile-description">Manage your team, members, and access settings.</p>
    </header>
    {{-- Feeds the page search with the team pages. --}}
    <div hidden data-settings-search-items="{{ json_encode(settingsSearchItems(['Team' => $teamMenuItems], [])) }}"></div>
    <div class="min-w-0">
        {{ $slot }}
    </div>
</section>
