<?php

use App\Models\Service;
use App\Services\TemplateFingerprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('template_reference_hash')->nullable()->after('service_type');
            $table->string('template_dismissed_hash')->nullable()->after('template_reference_hash');
        });

        // Backfill existing services to "up to date": store the current template
        // hash for each service_type so only FUTURE template changes flag them.
        $templates = get_service_templates();
        $hashByType = [];

        Service::query()
            ->whereNotNull('service_type')
            ->whereNull('template_reference_hash')
            ->chunkById(200, function ($services) use ($templates, &$hashByType) {
                foreach ($services as $service) {
                    $type = $service->service_type;
                    if (! array_key_exists($type, $hashByType)) {
                        $hashByType[$type] = TemplateFingerprint::forTemplate($templates[$type] ?? []);
                    }
                    if ($hashByType[$type] !== null) {
                        $service->template_reference_hash = $hashByType[$type];
                        $service->saveQuietly();
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['template_reference_hash', 'template_dismissed_hash']);
        });
    }
};
