<?php

it('delegates dev firewall commands to the coolify CLI instead of calling coold APIs directly', function () {
    $script = file_get_contents(base_path('scripts/dev.sh'));

    expect($script)->toContain('coolify_firewall()')
        ->and($script)->toContain('"$(coolify_cli_bin)" firewall "$command"')
        ->and($script)->toContain('--nodes "$nodes"')
        ->and($script)->toContain('--ssh-config "$ssh_config"')
        ->and($script)->not->toContain('/api/v1/firewall/allow')
        ->and($script)->not->toContain('firewall_api_for_each_vm');
});

it('installs the coolify CLI in both application container images', function (string $dockerfile) {
    $contents = file_get_contents(base_path($dockerfile));

    expect($contents)->toContain('ARG COOLIFY_CLI_VERSION=nightly')
        ->and($contents)->toContain('coolify-linux-musl-${COOLIFY_CLI_ARCH}.tar.gz')
        ->and($contents)->toContain('install -m 0755 /tmp/coolify /usr/local/bin/coolify');
})->with([
    'development image' => 'docker/development/Dockerfile',
    'production image' => 'docker/production/Dockerfile',
]);

it('does not require predefined UI node environment variables in the development app container', function () {
    $compose = file_get_contents(base_path('docker-compose.dev.yml'));
    $config = file_get_contents(base_path('config/coold.php'));

    expect($compose)->not->toContain('COOLIFY_CLI_NODES:')
        ->and($compose)->not->toContain('COOLIFY_CLI_SSH_CONFIG:')
        ->and($compose)->not->toContain('COOLIFY_CLI_WG_LISTEN_PORT_OVERRIDES:')
        ->and($compose)->not->toContain('COOLIFY_CLI_WG_ENDPOINT_OVERRIDES:')
        ->and($compose)->not->toContain('COOLIFY_CLI_TIMEOUT:')
        ->and($config)->not->toContain('cli_nodes')
        ->and($config)->not->toContain('COOLIFY_CLI_NODES');
});

it('supports a naked up mode that starts VMs and Spin but skips server bootstrap wiring', function () {
    $script = file_get_contents(base_path('scripts/dev.sh'));

    expect($script)->toContain('local naked=false')
        ->and($script)->toContain('--naked')
        ->and($script)->toContain('if [ "${#spin_args[@]}" -gt 0 ]; then')
        ->and($script)->toContain('spin up -d "${spin_args[@]}"')
        ->and($script)->toContain('spin up -d')
        ->and($script)->toContain('if [ "$naked" = "true" ]; then')
        ->and($script)->toContain('Skipping coolify bootstrap and Flux VM wiring')
        ->and($script)->toContain('coolify_bootstrap_with_retry')
        ->and($script)->toContain('configure_flux_dev_for_vm "$index"')
        ->and($script)->toContain('sync_v5_dev_lima_servers');
});

it('retries dev coolify bootstrap because fresh Lima setup can complete across partial phases', function () {
    $script = file_get_contents(base_path('scripts/dev.sh'));

    expect($script)->toContain('coolify_bootstrap_with_retry()')
        ->and($script)->toContain('local attempts=5')
        ->and($script)->toContain('if coolify_bootstrap; then')
        ->and($script)->toContain('fresh Lima hosts can finish setup after partial bootstrap phases');
});

it('syncs bootstrapped Lima VMs into v5 development server state', function () {
    $script = file_get_contents(base_path('scripts/dev.sh'));

    expect($script)->toContain('sync_v5_dev_lima_servers()')
        ->and($script)->toContain('v5:sync-dev-lima-servers')
        ->and($script)->toContain('--cluster="Development-Lima"')
        ->and($script)->toContain('--server "${instance}|${node}|$(coolify_ssh_user)|22"');
});

it('supports down cleanup as the preferred VM cleanup command', function () {
    $script = file_get_contents(base_path('scripts/dev.sh'));

    expect($script)->toContain('local cleanup=false')
        ->and($script)->toContain('--cleanup')
        ->and($script)->toContain('if [ "$cleanup" = "true" ]; then')
        ->and($script)->toContain('clean_vms')
        ->and($script)->toContain('down --cleanup')
        ->and($script)->toContain('clean-vms Delete the coold Lima VMs and all VM-local runtime state (alias for down --cleanup)');
});
