<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerHostScan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerHostScan>
 */
class ServerHostScanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'summary' => ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0, 'fixable' => 0, 'exploited' => 0, 'likely' => 0, 'total' => 0],
            'findings' => [],
            'findings_truncated' => false,
            'grype_db_built_at' => now()->subHours(6),
            'scanned_at' => now(),
        ];
    }
}
