<?php

namespace App\Actions\Development;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Installs the NVIDIA driver and the NVIDIA Container Toolkit on an Ubuntu development VM with a passed-through GPU,
 * then refreshes the server details so Coolify shows the GPU. Steps that are already done are skipped.
 *
 * @see https://ubuntu.com/server/docs/how-to/graphics/install-nvidia-drivers/
 * @see https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/latest/install-guide.html
 */
class InstallDevelopmentGpuDriver
{
    use AsAction;

    public function handle(Server $server): void
    {
        instant_remote_process([
            'set -e',
            'export DEBIAN_FRONTEND=noninteractive',
            'if ! nvidia-smi >/dev/null 2>&1; then',
            'apt-get update -qq',
            'apt-get install -y -qq ubuntu-drivers-common',
            'ubuntu-drivers install --gpgpu',
            // For example 580-server-open. The utilities package has no -open suffix.
            'flavor=$(dpkg-query -W -f=\'${Package}\n\' \'nvidia-headless-no-dkms-*\' | sed -n \'s/^nvidia-headless-no-dkms-//p\' | head -n1)',
            // ubuntu-drivers installs the modules for the newest kernel, which can differ from the running one.
            'apt-get install -y -qq "nvidia-utils-${flavor%-open}" "linux-modules-nvidia-${flavor}-$(uname -r)"',
            'modprobe nvidia',
            'nvidia-smi',
            'fi',
            'if ! command -v nvidia-ctk >/dev/null 2>&1; then',
            'curl -fsSL https://nvidia.github.io/libnvidia-container/gpgkey | gpg --batch --yes --dearmor -o /usr/share/keyrings/nvidia-container-toolkit-keyring.gpg',
            'curl -fsSL https://nvidia.github.io/libnvidia-container/stable/deb/nvidia-container-toolkit.list | sed \'s#deb https://#deb [signed-by=/usr/share/keyrings/nvidia-container-toolkit-keyring.gpg] https://#g\' > /etc/apt/sources.list.d/nvidia-container-toolkit.list',
            'apt-get update -qq',
            'apt-get install -y -qq nvidia-container-toolkit',
            'nvidia-ctk runtime configure --runtime=docker',
            'systemctl restart docker',
            'fi',
        ], $server, timeout: 1800, disableMultiplexing: true, maxAttempts: 1);

        $server->gatherServerMetadata();
    }
}
