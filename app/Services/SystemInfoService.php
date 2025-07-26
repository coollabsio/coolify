<?php

/**
 * SystemInfoService
 * -----------------
 * This service provides system-level information for the dashboard widget,
 * including memory, uptime, disk usage, Docker stats, and more.
 *
 * Created by Mustafa Ramadan (Steve-Sy)
 * Last updated: 26-07-2025
 * Purpose: Used in Coolify dashboard to show real-time server info.
 */

namespace App\Services;

class SystemInfoService
{
    public function get(): array
    {
        $static = $this->getStaticInfo();

        return [
            ...$static,
            'memory' => $this->getMemoryInfo(),
            'uptime' => $this->getUptime(),
            'datetime' => now()->format('Y-m-d H:i:s'),
            'disk_usage' => $this->getDiskUsage(),
            'load_average' => $this->getLoadAverage(),
            'containers' => $this->getContainerStats(),
            'swap_usage' => $this->getSwapUsage(),
        ];
    }

    private function getOsName(): string
    {
        // Works on most Linux distros
        if (file_exists('/etc/os-release')) {
            $content = file_get_contents('/etc/os-release');
            if (preg_match('/^PRETTY_NAME="(.+)"$/m', $content, $matches)) {
                return $matches[1]; // e.g. Ubuntu 24.04 LTS
            }
        }

        return php_uname('s'); // fallback
    }

    private function getPublicIP(): ?string
    {
        return trim(@file_get_contents('https://api.ipify.org'));
    }

    private function getCpuCores(): int
    {
        return (int) shell_exec('nproc') ?: 0;
    }

    private function getMemoryInfo(): array
    {
        $mem = explode("\n", trim(shell_exec("grep -E 'MemTotal|MemAvailable' /proc/meminfo")));
        $total = (int) filter_var($mem[0], FILTER_SANITIZE_NUMBER_INT);
        $available = (int) filter_var($mem[1], FILTER_SANITIZE_NUMBER_INT);
        $used = $total - $available;

        return [
            'total' => round($total / 1024 / 1024, 2),     // GB
            'used' => round($used / 1024 / 1024, 2),        // GB
        ];
    }

    private function getUptime(): string
    {
        $seconds = (int) shell_exec("cat /proc/uptime | awk '{print int($1)}'");
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return "{$hours}h {$minutes}m";
    }

    private function getDiskUsage(): string
    {
        return trim(shell_exec("df -h / | awk 'NR==2 {print $3 \"/\" $2}'"));
    }

    private function getLoadAverage(): string
    {
        return trim(shell_exec("uptime | awk -F'load average:' '{ print $2 }'"));
    }

    private function getCpuModel(): string
    {
        $lscpu = shell_exec('which lscpu');
        if ($lscpu) {
            return trim(shell_exec("lscpu | grep 'Model name' | awk -F ':' '{print $2}'"));
        }

        // Fallback to /proc/cpuinfo (works on all Linux distros)
        $cpuInfo = shell_exec("grep 'model name' /proc/cpuinfo | head -n 1");

        return $cpuInfo ? trim(explode(':', $cpuInfo)[1]) : 'Unknown';
    }

    private function getContainerStats(): array
    {
        $running = (int) shell_exec('docker ps -q | wc -l');
        $total = (int) shell_exec('docker ps -aq | wc -l');

        return ['running' => $running, 'total' => $total];
    }

    private function getSwapUsage(): string
    {
        $output = shell_exec('free -h | grep Swap');
        if (! $output) {
            return 'N/A';
        }

        [$label, $total, $used] = preg_split('/\s+/', trim($output));

        return "$used / $total";
    }

    // This method caches the static info for 24 hours, clear by cache()->forget('server_static_info');
    private function getStaticInfo(): array
    {
        return cache()->remember('server_static_info', now()->addDay(), function () {
            return [
                'os' => $this->getOsName(),
                'hostname' => gethostname(),
                'public_ip' => $this->getPublicIP(),
                'cpu_cores' => $this->getCpuCores(),
                'cpu_model' => $this->getCpuModel(),
            ];
        });
    }
}
