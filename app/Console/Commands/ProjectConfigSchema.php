<?php

namespace App\Console\Commands;

use App\Services\ProjectConfig\AdapterRegistry;
use Illuminate\Console\Command;

class ProjectConfigSchema extends Command
{
    protected $signature = 'project-config:schema';

    protected $description = 'Write the coolify.json JSON schema to public/schemas/coolify.v1.json';

    public function handle(AdapterRegistry $registry): int
    {
        $path = public_path('schemas/coolify.v1.json');
        file_put_contents($path, json_encode($registry->schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->info("Wrote {$path}");

        return self::SUCCESS;
    }
}
