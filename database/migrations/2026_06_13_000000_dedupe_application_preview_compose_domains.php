<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('application_previews')
            ->whereNotNull('docker_compose_domains')
            ->orderBy('id')
            ->each(function ($preview) {
                $domains = json_decode($preview->docker_compose_domains, true);

                if (! is_array($domains) || $domains === []) {
                    return;
                }

                $normalized = [];
                foreach ($domains as $serviceName => $config) {
                    $key = str_replace(['-', '.'], '_', (string) $serviceName);

                    // On collision keep the entry that has a domain set.
                    if (! array_key_exists($key, $normalized) || (empty(data_get($normalized, "$key.domain")) && ! empty(data_get($config, 'domain')))) {
                        $normalized[$key] = $config;
                    }
                }

                if ($normalized !== $domains) {
                    DB::table('application_previews')
                        ->where('id', $preview->id)
                        ->update(['docker_compose_domains' => json_encode($normalized)]);
                }
            });
    }

    public function down(): void
    {
        // Data normalization cannot be reversed.
    }
};
