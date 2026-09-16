<?php

namespace App\Services;

use App\Support\Metrics\PressureLevel;

class FleetMetricsAggregator
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function fleetKpis(array $rows): array
    {
        $online = array_values(array_filter($rows, fn ($r) => $r['online'] === true));

        $cpuValues = self::present($online, 'cpu');
        $cpuAvg = $cpuValues === [] ? 0.0 : round(array_sum($cpuValues) / count($cpuValues), 1);

        $busiest = null;
        foreach ($online as $r) {
            if ($r['cpu'] !== null && ($busiest === null || $r['cpu'] > $busiest['percent'])) {
                $busiest = ['name' => $r['name'], 'percent' => (float) $r['cpu']];
            }
        }

        $memUsed = (int) self::sum($online, 'memUsed');
        $memTotal = (int) self::sum($online, 'memTotal');
        $diskUsed = (int) self::sum($online, 'diskUsed');
        $diskTotal = (int) self::sum($online, 'diskTotal');
        $load1 = self::present($online, 'load1');

        $needsAttention = 0;
        foreach ($online as $r) {
            $hot = PressureLevel::for($r['cpu'], 'cpu') !== 'ok'
                || PressureLevel::for(self::percent($r['memUsed'], $r['memTotal']), 'memory') !== 'ok'
                || PressureLevel::for($r['diskPercent'], 'disk') !== 'ok';
            if ($hot) {
                $needsAttention++;
            }
        }

        return [
            'serversOnline' => count($online),
            'serversTotal' => count($rows),
            'needsAttention' => $needsAttention,
            'cpuAvg' => $cpuAvg,
            'cpuApproximate' => count($cpuValues) > 1,
            'cpuBusiest' => $busiest,
            'memUsed' => $memUsed,
            'memTotal' => $memTotal,
            'memPercent' => self::percent($memUsed, $memTotal),
            'diskUsed' => $diskUsed,
            'diskTotal' => $diskTotal,
            'diskPercent' => self::percent($diskUsed, $diskTotal),
            'netRx' => (float) self::sum($online, 'netRx'),
            'netTx' => (float) self::sum($online, 'netTx'),
            'loadBusiest' => $load1 === [] ? 0.0 : max($load1),
            'loadAvg' => $load1 === [] ? 0.0 : round(array_sum($load1) / count($load1), 2),
            'containers' => (int) self::sum($online, 'containers'),
        ];
    }

    /**
     * @param  array<int, array<int, array{0: int, 1: float}>>  $seriesPerServer
     * @return array<int, array{0: int, 1: float}>
     */
    public static function sumSeriesByBucket(array $seriesPerServer, string $mode): array
    {
        $totals = [];
        $counts = [];

        foreach ($seriesPerServer as $series) {
            foreach ($series as [$ts, $value]) {
                $totals[$ts] = ($totals[$ts] ?? 0.0) + (float) $value;
                $counts[$ts] = ($counts[$ts] ?? 0) + 1;
            }
        }

        ksort($totals);

        $out = [];
        foreach ($totals as $ts => $sum) {
            $out[] = [(int) $ts, $mode === 'avg' ? round($sum / $counts[$ts], 2) : round($sum, 2)];
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $containers
     * @return array<int, array<string, mixed>>
     */
    public static function rankContainers(array $containers, string $metric, int $limit = 20): array
    {
        $key = match ($metric) {
            'memory' => 'memUsed',
            'disk' => 'diskBytes',
            'network' => 'net',
            default => 'cpu',
        };

        usort($containers, fn ($a, $b) => ($b[$key] ?? 0) <=> ($a[$key] ?? 0));

        return array_slice($containers, 0, $limit);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, float>
     */
    private static function present(array $rows, string $key): array
    {
        return array_values(array_map(
            fn ($r) => (float) $r[$key],
            array_filter($rows, fn ($r) => $r[$key] !== null)
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function sum(array $rows, string $key): float
    {
        return array_sum(array_map(fn ($r) => $r[$key] ?? 0, $rows));
    }

    private static function percent(int|float|null $used, int|float|null $total): float
    {
        if (! $total) {
            return 0.0;
        }

        return round(($used / $total) * 100, 1);
    }
}
