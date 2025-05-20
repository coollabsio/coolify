<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class CollectServerInfo
{
    use AsAction;

    public function handle(Server $server)
    {
        try {
            // Collect CPU information
            $this->collectCpuInfo($server);

            // Collect memory information
            $this->collectMemoryInfo($server);

            // Collect disk information
            $this->collectDiskInfo($server);

            // Collect GPU information
            $this->collectGpuInfo($server);

            // Collect OS information
            $this->collectOsInfo($server);

            return true;
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }

    private function collectCpuInfo(Server $server)
    {
        // Get CPU model
        $cpuModel = instant_remote_process([
            "cat /proc/cpuinfo | grep 'model name' | head -1 | awk -F: '{print $2}' | xargs"
        ], $server, false);

        // Get CPU cores
        $cpuCores = instant_remote_process([
            "nproc"
        ], $server, false);

        // Get CPU speed
        $cpuSpeed = instant_remote_process([
            "cat /proc/cpuinfo | grep 'cpu MHz' | head -1 | awk -F: '{print $2}' | xargs"
        ], $server, false);

        // Save to database
        $server->settings->cpu_model = $cpuModel;
        $server->settings->cpu_cores = $cpuCores;
        $server->settings->cpu_speed = $cpuSpeed ? $cpuSpeed . ' MHz' : null;
        $server->settings->save();
    }

    private function collectMemoryInfo(Server $server)
    {
        // Get total memory
        $memoryTotal = instant_remote_process([
            "free -h | grep 'Mem:' | awk '{print $2}'"
        ], $server, false);

        // Get memory speed (requires dmidecode which might not be available on all systems)
        $memorySpeed = instant_remote_process([
            "command -v dmidecode >/dev/null && dmidecode -t memory | grep 'Speed' | head -1 | awk '{print $2, $3}' || echo 'Not available'"
        ], $server, false);

        // Get swap total
        $swapTotal = instant_remote_process([
            "free -h | grep 'Swap:' | awk '{print $2}'"
        ], $server, false);

        // Save to database
        $server->settings->memory_total = $memoryTotal;
        $server->settings->memory_speed = $memorySpeed !== 'Not available' ? $memorySpeed : null;
        $server->settings->swap_total = $swapTotal;
        $server->settings->save();
    }

    private function collectDiskInfo(Server $server)
    {
        // Get disk information
        $diskInfo = instant_remote_process([
            "df -h / | tail -1 | awk '{print $2, $3, $4}'"
        ], $server, false);

        if ($diskInfo) {
            $diskParts = explode(' ', $diskInfo);
            if (count($diskParts) >= 3) {
                $server->settings->disk_total = $diskParts[0];
                $server->settings->disk_used = $diskParts[1];
                $server->settings->disk_free = $diskParts[2];
                $server->settings->save();
            }
        }
    }

    private function collectGpuInfo(Server $server)
    {
        // Try to get GPU model using lspci
        $gpuModel = instant_remote_process([
            "command -v lspci >/dev/null && lspci | grep -i 'vga\\|3d\\|2d' | head -1 | cut -d ':' -f3 | xargs || echo 'Not available'"
        ], $server, false);

        // Try to get GPU memory using nvidia-smi if available
        $gpuMemory = instant_remote_process([
            "command -v nvidia-smi >/dev/null && nvidia-smi --query-gpu=memory.total --format=csv,noheader || echo 'Not available'"
        ], $server, false);

        // Save to database
        $server->settings->gpu_model = $gpuModel !== 'Not available' ? $gpuModel : null;
        $server->settings->gpu_memory = $gpuMemory !== 'Not available' ? $gpuMemory : null;
        $server->settings->save();
    }

    private function collectOsInfo(Server $server)
    {
        // Get OS name and version
        $osInfo = instant_remote_process([
            "cat /etc/os-release | grep 'PRETTY_NAME' | cut -d '=' -f2 | tr -d '\"'"
        ], $server, false);

        // Get kernel version
        $kernelVersion = instant_remote_process([
            "uname -r"
        ], $server, false);

        // Get architecture
        $architecture = instant_remote_process([
            "uname -m"
        ], $server, false);

        // Parse OS name and version
        $osName = null;
        $osVersion = null;

        if ($osInfo) {
            // Try to split the OS info into name and version
            if (preg_match('/^(.*?)\s+(\d+.*)$/', $osInfo, $matches)) {
                $osName = trim($matches[1]);
                $osVersion = trim($matches[2]);
            } else {
                $osName = $osInfo;
            }
        }

        // Save to database
        $server->settings->os_name = $osName;
        $server->settings->os_version = $osVersion;
        $server->settings->kernel_version = $kernelVersion;
        $server->settings->architecture = $architecture;
        $server->settings->save();
    }
}
