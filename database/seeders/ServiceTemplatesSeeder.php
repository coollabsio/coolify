<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * NOTE: Coolify's primary template seeding mechanism reads from
 * templates/compose/*.yaml files and the ServiceTemplates.json.
 *
 * This seeder serves as a reference / fallback to manually upsert
 * the WordPress + OpenLiteSpeed template record when needed.
 *
 * In normal operation the template is auto-discovered from:
 *   templates/compose/wordpress-with-openlitespeed.yaml
 */
class ServiceTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        // Templates are seeded from templates/compose/ automatically.
        // No manual DB insert required — kept for reference only.
        $this->command->info('Service templates are loaded from templates/compose/ directory.');
    }
}
