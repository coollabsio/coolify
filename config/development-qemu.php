<?php

/*
 * Development QEMU VMs can run in two modes:
 *
 * - Legacy mode (DEVELOPMENT_QEMU_INSTANCE unset): one set of VMs on the libvirt
 *   "default" network (192.168.122.0/24, bridge virbr0).
 * - Instance mode (DEVELOPMENT_QEMU_INSTANCE + DEVELOPMENT_QEMU_SLOT set): every dev
 *   instance gets its own isolated libvirt network "coolify-dev-{slot}" (10.221.{slot}.0/24,
 *   bridge cdevbr{slot}) and its own domains named "coolify-dev-{instance}--{profile}".
 *   MAC addresses stay identical to legacy mode because the prepared images match them.
 *
 * Values are computed here so the host and the Coolify container resolve identical settings.
 */
$developmentQemuInstance = trim((string) env('DEVELOPMENT_QEMU_INSTANCE', ''));
$developmentQemuInstance = $developmentQemuInstance === '' ? null : $developmentQemuInstance;
$developmentQemuSlot = $developmentQemuInstance === null ? null : (int) env('DEVELOPMENT_QEMU_SLOT', 0);

$developmentQemuProfiles = [
    'ubuntu-root' => [
        'label' => 'Ubuntu 24.04 (root)',
        'domain' => 'coolify-dev-ubuntu-root',
        'uuid' => 'development-qemu-ubuntu-root',
        'name' => 'QEMU Ubuntu (root)',
        'ip' => '192.168.122.10',
        'user' => 'root',
        'mac' => '52:54:00:ca:00:01',
        'image' => 'ubuntu-noble-amd64.qcow2',
        'image_url' => 'https://cloud-images.ubuntu.com/noble/current/noble-server-cloudimg-amd64.img',
        'os_variant' => 'ubuntu24.04',
        'provisioner' => 'apt',
    ],
    'ubuntu-non-root' => [
        'label' => 'Ubuntu 24.04 (non-root)',
        'domain' => 'coolify-dev-ubuntu-non-root',
        'uuid' => 'development-qemu-ubuntu-non-root',
        'name' => 'QEMU Ubuntu (non-root)',
        'ip' => '192.168.122.11',
        'user' => 'coolify',
        'mac' => '52:54:00:ca:00:02',
        'image' => 'ubuntu-noble-amd64.qcow2',
        'image_url' => 'https://cloud-images.ubuntu.com/noble/current/noble-server-cloudimg-amd64.img',
        'os_variant' => 'ubuntu24.04',
        'provisioner' => 'apt',
    ],
    'debian-root' => [
        'label' => 'Debian 12 (root)',
        'domain' => 'coolify-dev-debian-root',
        'uuid' => 'development-qemu-debian-root',
        'name' => 'QEMU Debian (root)',
        'ip' => '192.168.122.20',
        'user' => 'root',
        'mac' => '52:54:00:ca:00:03',
        'image' => 'debian-12-amd64.qcow2',
        'image_url' => 'https://cloud.debian.org/images/cloud/bookworm/latest/debian-12-genericcloud-amd64.qcow2',
        'os_variant' => 'debian12',
        'provisioner' => 'apt',
    ],
    'debian-non-root' => [
        'label' => 'Debian 12 (non-root)',
        'domain' => 'coolify-dev-debian-non-root',
        'uuid' => 'development-qemu-debian-non-root',
        'name' => 'QEMU Debian (non-root)',
        'ip' => '192.168.122.21',
        'user' => 'coolify',
        'mac' => '52:54:00:ca:00:04',
        'image' => 'debian-12-amd64.qcow2',
        'image_url' => 'https://cloud.debian.org/images/cloud/bookworm/latest/debian-12-genericcloud-amd64.qcow2',
        'os_variant' => 'debian12',
        'provisioner' => 'apt',
    ],
    'centos-root' => [
        'label' => 'CentOS Stream 9 (root)',
        'domain' => 'coolify-dev-centos-root',
        'uuid' => 'development-qemu-centos-root',
        'name' => 'QEMU CentOS Stream (root)',
        'ip' => '192.168.122.30',
        'user' => 'root',
        'mac' => '52:54:00:ca:00:05',
        'image' => 'centos-stream-9-amd64.qcow2',
        'image_url' => 'https://cloud.centos.org/centos/9-stream/x86_64/images/CentOS-Stream-GenericCloud-9-latest.x86_64.qcow2',
        'os_variant' => 'centos-stream9',
        'provisioner' => 'rpm',
    ],
    'centos-non-root' => [
        'label' => 'CentOS Stream 9 (non-root)',
        'domain' => 'coolify-dev-centos-non-root',
        'uuid' => 'development-qemu-centos-non-root',
        'name' => 'QEMU CentOS Stream (non-root)',
        'ip' => '192.168.122.31',
        'user' => 'coolify',
        'mac' => '52:54:00:ca:00:06',
        'image' => 'centos-stream-9-amd64.qcow2',
        'image_url' => 'https://cloud.centos.org/centos/9-stream/x86_64/images/CentOS-Stream-GenericCloud-9-latest.x86_64.qcow2',
        'os_variant' => 'centos-stream9',
        'provisioner' => 'rpm',
    ],
    'alpine-root' => [
        'label' => 'Alpine Linux 3.24 (root)',
        'domain' => 'coolify-dev-alpine-root',
        'uuid' => 'development-qemu-alpine-root',
        'name' => 'QEMU Alpine (root)',
        'ip' => '192.168.122.40',
        'user' => 'root',
        'mac' => '52:54:00:ca:00:07',
        'image' => 'alpine-3.24-amd64.qcow2',
        'image_url' => 'https://dl-cdn.alpinelinux.org/alpine/latest-stable/releases/cloud/generic_alpine-3.24.1-x86_64-bios-cloudinit-r0.qcow2',
        'os_variant' => 'generic',
        'provisioner' => 'apk',
        'interface' => 'eth0',
    ],
    'alpine-non-root' => [
        'label' => 'Alpine Linux 3.24 (non-root)',
        'domain' => 'coolify-dev-alpine-non-root',
        'uuid' => 'development-qemu-alpine-non-root',
        'name' => 'QEMU Alpine (non-root)',
        'ip' => '192.168.122.41',
        'user' => 'coolify',
        'mac' => '52:54:00:ca:00:08',
        'image' => 'alpine-3.24-amd64.qcow2',
        'image_url' => 'https://dl-cdn.alpinelinux.org/alpine/latest-stable/releases/cloud/generic_alpine-3.24.1-x86_64-bios-cloudinit-r0.qcow2',
        'os_variant' => 'generic',
        'provisioner' => 'apk',
        'interface' => 'eth0',
    ],
];

foreach ($developmentQemuProfiles as $developmentQemuProfileKey => $developmentQemuProfile) {
    $developmentQemuProfile['template'] = "coolify-dev-{$developmentQemuProfileKey}";

    if ($developmentQemuInstance !== null) {
        $developmentQemuProfile['domain'] = "coolify-dev-{$developmentQemuInstance}--{$developmentQemuProfileKey}";
        $developmentQemuProfile['ip'] = "10.221.{$developmentQemuSlot}.".substr(strrchr($developmentQemuProfile['ip'], '.'), 1);
    }

    $developmentQemuProfiles[$developmentQemuProfileKey] = $developmentQemuProfile;
}

return [
    'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFuGmoeGq/pojrsyP1pszcNVuZx9iFkCELtxrh31QJ68',
    'storage_path' => env('DEVELOPMENT_QEMU_STORAGE_PATH', '/var/lib/libvirt/images/coolify-development'),
    'instance' => $developmentQemuInstance,
    'slot' => $developmentQemuSlot,
    'gateway' => $developmentQemuInstance === null ? '192.168.122.1' : "10.221.{$developmentQemuSlot}.1",
    'subnet' => $developmentQemuInstance === null ? '192.168.122.0/24' : "10.221.{$developmentQemuSlot}.0/24",
    'prefix' => 24,
    'dns' => '1.1.1.1',
    'memory' => 2048,
    'vcpus' => 2,
    'disk_size' => '20G',
    'libvirt_network' => $developmentQemuInstance === null ? 'default' : "coolify-dev-{$developmentQemuSlot}",
    'bridge' => $developmentQemuInstance === null ? 'virbr0' : "cdevbr{$developmentQemuSlot}",
    'docker_network' => env('DEVELOPMENT_QEMU_DOCKER_NETWORK') ?: 'coolify',
    'coolify_container' => env('DEVELOPMENT_QEMU_COOLIFY_CONTAINER') ?: 'coolify',
    'profiles' => $developmentQemuProfiles,
];
