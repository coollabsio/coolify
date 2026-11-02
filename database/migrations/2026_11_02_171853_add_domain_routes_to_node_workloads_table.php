<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->json('domain_routes')->nullable();
            $table->string('www_redirect')->default('both');
            $table->boolean('force_https')->default(true);
            $table->boolean('permanent_redirect')->default(true);
        });

        DB::table('node_workloads')->whereNotNull('domains')->chunkById(100, function ($workloads): void {
            foreach ($workloads as $workload) {
                $hosts = json_decode((string) $workload->domains, true);
                if (! is_array($hosts) || $hosts === []) {
                    continue;
                }
                $routes = array_map(fn ($host): array => [
                    'scheme' => 'https',
                    'host' => (string) $host,
                    'port' => (int) ($workload->http_port ?? 80),
                    'path' => '',
                    'noindex' => false,
                ], array_values($hosts));
                DB::table('node_workloads')->where('id', $workload->id)->update(['domain_routes' => json_encode($routes)]);
            }
        });

        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropColumn('http_port');
        });
    }

    public function down(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->unsignedInteger('http_port')->nullable();
        });

        DB::table('node_workloads')->whereNotNull('domain_routes')->chunkById(100, function ($workloads): void {
            foreach ($workloads as $workload) {
                $routes = json_decode((string) $workload->domain_routes, true);
                $port = is_array($routes) ? ($routes[0]['port'] ?? null) : null;
                DB::table('node_workloads')->where('id', $workload->id)->update(['http_port' => $port]);
            }
        });

        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropColumn(['domain_routes', 'www_redirect', 'force_https', 'permanent_redirect']);
        });
    }
};
