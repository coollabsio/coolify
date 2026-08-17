<?php

it('uses shared sidebar navigation for every team settings page', function () {
    $sidebar = file_get_contents(resource_path('views/components/team/settings-layout.blade.php'));
    $navbar = file_get_contents(resource_path('views/components/dashboard/navbar.blade.php'));

    foreach ([
        resource_path('views/livewire/team/index.blade.php'),
        resource_path('views/livewire/team/member/index.blade.php'),
        resource_path('views/livewire/team/admin-view.blade.php'),
        resource_path('views/livewire/team/danger-zone.blade.php'),
    ] as $view) {
        expect(file_get_contents($view))
            ->toContain('<x-team.settings-layout>')
            ->not->toContain('<x-team.navbar');
    }

    expect($sidebar)
        ->toContain("'label' => 'General'")
        ->toContain("'label' => 'Members'")
        ->toContain("'label' => 'Admin View'")
        ->toContain("'label' => 'Danger Zone'")
        ->toContain('application-settings-navigation')
        ->not->toContain('New team');
    expect(file_get_contents(resource_path('views/livewire/team/index.blade.php')))
        ->toContain('buttonTitle="New team"')
        ->not->toContain('Delete team');
    expect(file_get_contents(resource_path('views/livewire/team/danger-zone.blade.php')))
        ->toContain('Delete team')
        ->toContain('status="Permanent"')
        ->toContain('border-red-300')
        ->toContain("'Sources' => currentTeam()->sources()")
        ->not->toContain("'Sources' => currentTeam()->sources,");
    expect(file_get_contents(resource_path('views/livewire/switch-team.blade.php')))
        ->toContain('New team')
        ->toContain('team-switcher-create-expanded')
        ->toContain('team-switcher-create-collapsed');
    expect($navbar)
        ->toContain("request()->routeIs('team.index', 'team.member.index', 'team.admin-view', 'team.danger-zone')")
        ->not->toContain("['label' => 'Members', 'route' => 'team.member.index'");
});
