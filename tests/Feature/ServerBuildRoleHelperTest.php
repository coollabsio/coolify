<?php

test('server settings show all server roles and the build risk confirmation', function () {
    $view = file_get_contents(resource_path('views/livewire/server/show.blade.php'));

    expect($view)
        ->toContain('id="serverRole"')
        ->toContain("['value' => 'deployment', 'label' => 'Deployments only']")
        ->toContain("['value' => 'build', 'label' => 'Builds only']")
        ->toContain("['value' => 'both', 'label' => 'Deployments and builds']")
        ->toContain('<x-modal-confirmation title="Use this server for deployments and builds?"')
        ->not->toContain('<x-modal modalId="server-role-confirmation"')
        ->toContain('Use this server for deployments and builds?')
        ->toContain('can become slow or unreachable');
});

test('server role migration keeps dedicated build servers and defaults other servers to both', function () {
    $migration = file_get_contents(base_path('database/migrations/2026_09_19_121840_add_server_role_to_server_settings_table.php'));

    expect($migration)
        ->toContain('->nullable()->default(ServerRole::BOTH->value)')
        ->toContain("->where('is_build_server', true)")
        ->toContain("->update(['server_role' => ServerRole::BUILD->value])");
});
