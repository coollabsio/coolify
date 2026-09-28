<?php

test('server role migration keeps dedicated build servers and defaults other servers to both', function () {
    $migration = file_get_contents(base_path('database/migrations/2026_09_19_121840_add_server_role_to_server_settings_table.php'));

    expect($migration)
        ->toContain('->nullable()->default(ServerRole::BOTH->value)')
        ->toContain("->where('is_build_server', true)")
        ->toContain("->update(['server_role' => ServerRole::BUILD->value])");
});
