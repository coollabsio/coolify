<?php

use App\Models\Application;
use Illuminate\Database\Migrations\Migration;
use Symfony\Component\Yaml\Yaml;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Migrate docker_compose_domains to use original service names instead of transformed ones
        // This fixes collisions between services like "api.test" and "api-test"

        Application::where('build_pack', 'dockercompose')
            ->whereNotNull('docker_compose_domains')
            ->chunk(100, function ($applications) {
                foreach ($applications as $application) {
                    try {
                        $domains = collect(json_decode($application->docker_compose_domains, true));

                        if ($domains->isEmpty()) {
                            continue;
                        }

                        // Parse the compose file to get original service names
                        $compose = $application->parseCompose();
                        $services = data_get($compose, 'services', []);

                        if (empty($services)) {
                            continue;
                        }

                        // Create a mapping from transformed names to original names
                        $transformedToOriginal = [];
                        foreach ($services as $originalServiceName => $service) {
                            $transformedName = str($originalServiceName)->replace('-', '_')->replace('.', '_')->value();
                            $transformedToOriginal[$transformedName] = $originalServiceName;
                        }

                        // Migrate the domains to use original service names
                        $migratedDomains = collect();
                        foreach ($domains as $key => $value) {
                            // If the key is a transformed name, use the original name
                            if (isset($transformedToOriginal[$key])) {
                                $migratedDomains->put($transformedToOriginal[$key], $value);
                            } else {
                                // If we can't find a mapping, keep the original key
                                // (it might already be using the original service name)
                                $migratedDomains->put($key, $value);
                            }
                        }

                        // Update the application with migrated domains
                        $application->docker_compose_domains = $migratedDomains->toJson();
                        $application->save();

                    } catch (\Exception $e) {
                        // Log the error but continue with other applications
                        logger()->error('Failed to migrate docker_compose_domains for application '.$application->id.': '.$e->getMessage());
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse the migration by transforming service names back
        Application::where('build_pack', 'dockercompose')
            ->whereNotNull('docker_compose_domains')
            ->chunk(100, function ($applications) {
                foreach ($applications as $application) {
                    try {
                        $domains = collect(json_decode($application->docker_compose_domains, true));

                        if ($domains->isEmpty()) {
                            continue;
                        }

                        // Transform all keys back to underscore format
                        $transformedDomains = collect();
                        foreach ($domains as $key => $value) {
                            $transformedKey = str($key)->replace('-', '_')->replace('.', '_')->value();
                            $transformedDomains->put($transformedKey, $value);
                        }

                        $application->docker_compose_domains = $transformedDomains->toJson();
                        $application->save();

                    } catch (\Exception $e) {
                        logger()->error('Failed to reverse migrate docker_compose_domains for application '.$application->id.': '.$e->getMessage());
                    }
                }
            });
    }
};
