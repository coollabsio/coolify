<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class HostVitals extends Component
{
    #[Locked]
    public array $metrics = [
        'cpu' => [
            'cores' => 0,
            'used_percent' => null,
            'free_percent' => null,
        ],
        'ram' => [
            'used_gib' => 0.0,
            'available_gib' => 0.0,
            'total_gib' => 0.0,
            'used_percent' => 0.0,
            'available_percent' => 0.0,
        ],
        'disk' => [
            'used_gib' => 0.0,
            'free_gib' => 0.0,
            'total_gib' => 0.0,
            'used_percent' => 0.0,
            'free_percent' => 0.0,
        ],
        'load' => [
            'one' => 0.0,
            'five' => 0.0,
            'fifteen' => 0.0,
        ],
    ];

    #[Locked]
    public ?int $previousTotalTicks = null;

    #[Locked]
    public ?int $previousIdleTicks = null;

    public function mount(): void
    {
        $this->ensureAvailable();
        $this->readVitals(false);
    }

    public function refreshVitals(): void
    {
        $this->ensureAvailable();
        $this->readVitals(true);
    }

    private function ensureAvailable(): void
    {
        if (isCloud() || ! isInstanceAdmin()) {
            abort(403);
        }
    }

    private function readVitals(bool $calculateCpu): void
    {
        [$totalTicks, $idleTicks] = $this->cpuTicks();

        $cpuUsedPercent = $this->metrics['cpu']['used_percent'];
        if ($calculateCpu && $this->previousTotalTicks !== null && $totalTicks > $this->previousTotalTicks) {
            $deltaTotal = $totalTicks - $this->previousTotalTicks;
            $deltaIdle = $idleTicks - (int) $this->previousIdleTicks;
            $busyTicks = max(0, $deltaTotal - $deltaIdle);
            $cpuUsedPercent = round(min(100, ($busyTicks / $deltaTotal) * 100), 1);
        }

        $this->previousTotalTicks = $totalTicks;
        $this->previousIdleTicks = $idleTicks;

        $cpuInfo = @file_get_contents('/proc/cpuinfo') ?: '';
        preg_match_all('/^processor\s*:/m', $cpuInfo, $processorMatches);
        $cores = max(1, count($processorMatches[0] ?? []));

        $this->metrics['cpu'] = [
            'cores' => $cores,
            'used_percent' => $cpuUsedPercent,
            'free_percent' => $cpuUsedPercent === null ? null : round(max(0, 100 - $cpuUsedPercent), 1),
        ];

        $memInfo = @file_get_contents('/proc/meminfo') ?: '';
        preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $memInfo, $totalMemoryMatch);
        preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $memInfo, $availableMemoryMatch);

        $totalMemoryKb = max(0, (int) ($totalMemoryMatch[1] ?? 0));
        $availableMemoryKb = min($totalMemoryKb, max(0, (int) ($availableMemoryMatch[1] ?? 0)));
        $usedMemoryKb = max(0, $totalMemoryKb - $availableMemoryKb);

        $this->metrics['ram'] = [
            'used_gib' => round($usedMemoryKb / 1048576, 1),
            'available_gib' => round($availableMemoryKb / 1048576, 1),
            'total_gib' => round($totalMemoryKb / 1048576, 1),
            'used_percent' => $totalMemoryKb > 0 ? round(($usedMemoryKb / $totalMemoryKb) * 100, 1) : 0.0,
            'available_percent' => $totalMemoryKb > 0 ? round(($availableMemoryKb / $totalMemoryKb) * 100, 1) : 0.0,
        ];

        $diskTotalBytes = max(0, (float) (@disk_total_space('/') ?: 0));
        $diskFreeBytes = min($diskTotalBytes, max(0, (float) (@disk_free_space('/') ?: 0)));
        $diskUsedBytes = max(0, $diskTotalBytes - $diskFreeBytes);

        $this->metrics['disk'] = [
            'used_gib' => round($diskUsedBytes / 1073741824, 1),
            'free_gib' => round($diskFreeBytes / 1073741824, 1),
            'total_gib' => round($diskTotalBytes / 1073741824, 1),
            'used_percent' => $diskTotalBytes > 0 ? round(($diskUsedBytes / $diskTotalBytes) * 100, 1) : 0.0,
            'free_percent' => $diskTotalBytes > 0 ? round(($diskFreeBytes / $diskTotalBytes) * 100, 1) : 0.0,
        ];

        $load = sys_getloadavg() ?: [0.0, 0.0, 0.0];
        $this->metrics['load'] = [
            'one' => round((float) ($load[0] ?? 0), 2),
            'five' => round((float) ($load[1] ?? 0), 2),
            'fifteen' => round((float) ($load[2] ?? 0), 2),
        ];
    }

    private function cpuTicks(): array
    {
        $lines = @file('/proc/stat', FILE_IGNORE_NEW_LINES) ?: [];
        $firstLine = $lines[0] ?? '';
        $parts = preg_split('/\s+/', trim($firstLine)) ?: [];

        if (($parts[0] ?? '') === 'cpu') {
            array_shift($parts);
        }

        $ticks = array_map('intval', array_slice($parts, 0, 8));
        $ticks = array_pad($ticks, 8, 0);

        return [array_sum($ticks), $ticks[3] + $ticks[4]];
    }

    public function render(): View
    {
        return view('livewire.host-vitals');
    }
}
