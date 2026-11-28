<?php

use App\Models\ServiceApplication;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Garage, MinIO and Logto were routed from their URL variables, and containers with several magic URL
     * names routed only one of them. Each endpoint now has its domains on the Domains page, so take over the
     * URLs once. New services take them over when they are parsed.
     */
    public function up(): void
    {
        ServiceApplication::query()
            ->whereNull('domain_endpoints')
            ->where(function ($query): void {
                foreach (SPECIFIC_SERVICES as $image) {
                    $query->orWhere('image', $image)->orWhere('image', 'like', "{$image}:%");
                }
                // Containers with several magic URL names.
                $query->orWhereHas('service', fn ($service) => $service->where('docker_compose_raw', 'like', '%SERVICE_URL_%')
                    ->orWhere('docker_compose_raw', 'like', '%SERVICE_FQDN_%'));
            })
            ->with('service.server')
            ->chunkById(100, function ($applications): void {
                foreach ($applications as $application) {
                    try {
                        $application->importServiceEndpoints();
                    } catch (Throwable $e) {
                        Log::warning("Could not import the endpoint domains of service application {$application->uuid}: {$e->getMessage()}");
                    }
                }
            });
    }

    public function down(): void
    {
        // The variables keep the URLs, and the domains route the same endpoints.
    }
};
