<?php

it('does not expose removed dev firewall commands', function () {
    $script = file_get_contents(base_path('scripts/dev.sh'));

    expect($script)->not->toContain('coolify_firewall()')
        ->and($script)->not->toContain('scripts/dev.sh firewall')
        ->and($script)->not->toContain('firewall <command>')
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

it('runs the coolify CLI from the development application container', function () {
    $script = file_get_contents(base_path('scripts/dev.sh'));

    expect($script)->toContain("printf '%s\\n' '/usr/local/bin/coolify'")
        ->and($script)->toContain('docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T coolify "$(coolify_cli_bin)" init bootstrap')
        ->and($script)->toContain('docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T coolify "$(coolify_cli_bin)" "$@"')
        ->and($script)->toContain('ensure_coolify_container_ssh_key')
        ->and($script)->not->toContain('.dev/bin/coolify')
        ->and($script)->not->toContain('coolify-${os}-${arch}.tar.gz');
});

it('syncs host-resolved Lima local names into the Coolify container hosts file', function () {
    $script = file_get_contents(base_path('scripts/dev.sh'));

    expect($script)->toContain('resolve_lima_dns_name()')
        ->and($script)->toContain('dscacheutil -q host -a name "$name"')
        ->and($script)->toContain('getent ahostsv4 "$name"')
        ->and($script)->toContain('sync_lima_hosts_into_coolify_container()')
        ->and($script)->toContain('docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T -u root coolify sh -lc')
        ->and($script)->toContain('cat "$next" > /etc/hosts')
        ->and($script)->toContain('sync_lima_hosts_into_coolify_container')
        ->and($script)->toContain('if [ "$naked" = "true" ]; then');
});

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

it('supports a naked up mode that starts VMs and Docker Compose but skips server bootstrap wiring', function () {
    $script = file_get_contents(base_path('scripts/dev.sh'));

    expect($script)->toContain('local naked=false')
        ->and($script)->toContain('--naked')
        ->and($script)->toContain('if [ "${#compose_args[@]}" -gt 0 ]; then')
        ->and($script)->toContain('docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d "${compose_args[@]}"')
        ->and($script)->toContain('docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d')
        ->and($script)->toContain('if [ "$naked" = "true" ]; then')
        ->and($script)->toContain('Skipping coolify bootstrap and Flux VM wiring')
        ->and($script)->toContain('coolify_bootstrap_with_retry')
        ->and($script)->toContain('--concurrency "$(coolify_bootstrap_concurrency)"')
        ->and($script)->toContain('--ssh-timeout "$(coolify_bootstrap_ssh_timeout)"')
        ->and($script)->toContain('--debug')
        ->and($script)->toContain('configure_flux_dev_for_vm "$index"')
        ->and($script)->toContain('sync_v5_dev_lima_servers');
});

it('retries dev coolify bootstrap because fresh Lima setup can complete across partial phases', function () {
    $script = file_get_contents(base_path('scripts/dev.sh'));

    expect($script)->toContain('coolify_bootstrap_with_retry()')
        ->and($script)->toContain('local attempts=5')
        ->and($script)->toContain('if coolify_bootstrap; then')
        ->and($script)->toContain('diagnose_coold_bootstrap_failure')
        ->and($script)->toContain('systemctl --failed --no-pager')
        ->and($script)->toContain('systemctl --no-pager --full status wg-quick@wg0 podman.socket corrosion coold')
        ->and($script)->toContain('fresh Lima hosts can finish setup after partial bootstrap phases');
});

it('seeds bootstrapped Lima VMs into v5 development server state', function () {
    $script = file_get_contents(base_path('scripts/dev.sh'));
    $compose = file_get_contents(base_path('docker-compose.dev.yml'));

    expect($script)->toContain('sync_v5_dev_lima_servers()')
        ->and($script)->toContain('COOLIFY_CLI_SSH_USER="$ssh_user"')
        ->and($script)->toContain('COOLIFY_CLI_SSH_USER="$(coolify_ssh_user)" docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d')
        ->and($script)->toContain('coold_vm_dns_name()')
        ->and($script)->toContain('$(coold_vm_dns_name "$index")')
        ->and($script)->toContain('v5:sync-dev-lima-servers')
        ->and($script)->toContain('--server="${instance}|$(coold_vm_dns_name "$index")|${ssh_user}|22|$(coold_vm_wg_ip "$index")"')
        ->and($compose)->toContain('COOLIFY_CLI_SSH_USER: "${COOLIFY_CLI_SSH_USER:-}"')
        ->and($script)->not->toContain('db:seed --class=V5DevLimaSeeder --force')
        ->and($script)->not->toContain('host.docker.internal|${ssh_user}');
});

it('uses the shared Lima template directly instead of generated per-VM YAML', function () {
    $script = file_get_contents(base_path('scripts/dev.sh'));
    $vmScript = file_get_contents(base_path('scripts/coold-vm.sh'));
    $template = file_get_contents(base_path('dev/lima/coold.yaml'));

    expect($script)->toContain('coold_vm_dns_name()')
        ->and($script)->not->toContain('COOLIFY_COOLD_VM_SSH_PORT="$(coold_vm_ssh_port "$index")"')
        ->and($vmScript)->toContain('limactl start --tty=false --name="$INSTANCE" "$TEMPLATE"')
        ->and($vmScript)->not->toContain('GENERATED=')
        ->and($vmScript)->not->toContain('generate_yaml()')
        ->and($template)->not->toContain('{{COOLIFY_COOLD_VM_SSH_PORT}}');
});

it('sets the Lima guest hostname to the instance name for predictable mDNS', function () {
    $vmScript = file_get_contents(base_path('scripts/coold-vm.sh'));
    $template = file_get_contents(base_path('dev/lima/coold.yaml'));

    expect($vmScript)->toContain('ensure_mdns_hostname()')
        ->and($vmScript)->toContain('hostnamectl set-hostname "$INSTANCE"')
        ->and($vmScript)->toContain('systemctl restart avahi-daemon.service')
        ->and($vmScript)->toContain('ensure_mdns_hostname')
        ->and($template)->toContain('hostnamectl set-hostname "{{.Name}}"')
        ->and($template)->not->toContain('hostnamectl set-hostname myvm');
});

it('authorizes the seeded testing host key in dev Lima VMs', function () {
    $template = file_get_contents(base_path('dev/lima/coold.yaml'));

    expect($template)->toContain('coolify_test_public_key=')
        ->and($template)->toContain('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFuGmoeGq/pojrsyP1pszcNVuZx9iFkCELtxrh31QJ68')
        ->and($template)->toContain('authorized_keys')
        ->and($template)->toContain('grep -qxF "$coolify_test_public_key"')
        ->and($template)->toContain('install_public_key root')
        ->and($template)->toContain('install_public_key coolify')
        ->and($template)->toContain('owner="$(stat -c \'%u:%g\' "$home")"')
        ->and($template)->toContain('hostnamectl set-hostname "{{.Name}}"')
        ->and($template)->not->toContain('hostnamectl set-hostname myvm')
        ->and($template)->not->toContain('mode: user')
        ->and($template)->not->toContain('user="$(basename "$home")"');
});

it('hardcodes the naked Lima VM ssh port for bootstrap testing', function () {
    $template = file_get_contents(base_path('.dev/lima/coolify-naked-test.yaml'));

    expect($template)->toContain('ssh:')
        ->and($template)->toContain('localPort: 60003');
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

it('scripts a full fresh dev reset with bounded Lima startup retries', function () {
    $script = file_get_contents(base_path('scripts/dev.sh'));
    $vmScript = file_get_contents(base_path('scripts/coold-vm.sh'));

    expect($script)->toContain('fresh()')
        ->and($script)->toContain('down --cleanup')
        ->and($script)->toContain('COOLIFY_DEV_FOLLOW_LOGS=false up')
        ->and($script)->toContain('php artisan migrate:fresh --seed --force')
        ->and($script)->toContain('sync_v5_dev_lima_servers')
        ->and($script)->toContain('recreate_naked_lima_vm')
        ->and($script)->toContain('COOLIFY_NAKED_VM_START_TIMEOUT')
        ->and($script)->toContain('limactl start --tty=false --name="$instance" "$config"')
        ->and($script)->toContain('cleanup_lima_instance_processes "$instance"')
        ->and($script)->toContain('did not become ready after ${attempts} attempts')
        ->and($script)->toContain('refresh_test_host_key')
        ->and($script)->toContain('coold_vm_up_with_retry()')
        ->and($script)->toContain('COOLIFY_COOLD_BOOTSTRAP_CONCURRENCY 1')
        ->and($script)->toContain('COOLIFY_COOLD_BOOTSTRAP_SSH_TIMEOUT 90s')
        ->and($script)->toContain('local attempts=2')
        ->and($script)->toContain('deleting and retrying with a fresh Lima instance')
        ->and($script)->toContain('cleanup_lima_instance_processes()')
        ->and($script)->toContain('kill_matching_processes()')
        ->and($script)->toContain('kill -9 $pids')
        ->and($script)->toContain('limactl hostagent .*${instance}')
        ->and($script)->toContain('ssh: .*/.lima/${instance}/ssh.sock')
        ->and($vmScript)->toContain('COOLIFY_COOLD_VM_START_TIMEOUT')
        ->and($vmScript)->toContain('lima_shell_timeout()')
        ->and($vmScript)->toContain('cleanup_lima_probe_processes()')
        ->and($vmScript)->toContain('cleanup_lima_hostagent_processes()')
        ->and($vmScript)->toContain('kill_matching_processes()')
        ->and($vmScript)->toContain('pkill -P "$pid"')
        ->and($vmScript)->toContain('lima_shell_timeout 5 true')
        ->and($vmScript)->toContain('Lima start timed out after ${START_TIMEOUT}s')
        ->and($vmScript)->toContain('Guest SSH timed out after ${START_TIMEOUT}s')
        ->and($vmScript)->toContain('Guest provisioning timed out after ${START_TIMEOUT}s');
});
