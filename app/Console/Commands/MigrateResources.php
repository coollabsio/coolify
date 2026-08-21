<?php

namespace App\Console\Commands;

use App\Actions\Migration\RunMigration;
use Illuminate\Console\Command;

class MigrateResources extends Command
{
    protected $signature = 'coolify:migrate
                            {--source-url= : Source Coolify URL}
                            {--source-token= : Source API token}
                            {--target-url= : Target Coolify URL}
                            {--target-token= : Target API token}
                            {--storage=s3 : Storage driver (s3, local-ssh, azure, gcs)}
                            {--storage-endpoint= : S3-compatible endpoint}
                            {--storage-bucket= : Bucket or container name}
                            {--storage-region= : Storage region}
                            {--storage-key= : Storage access key}
                            {--storage-secret= : Storage secret}
                            {--s3-storage-uuid= : Existing team S3 storage UUID}
                            {--destination= : Target destination UUID}
                            {--project= : Target project UUID}
                            {--environment= : Target environment UUID}
                            {--resources= : Comma-separated source resource UUIDs}
                            {--skip-data : Export and import metadata only}
                            {--dry-run : Discover and validate without migrating}';

    protected $description = 'Migrate resources from one Coolify instance to another.';

    public function handle(RunMigration $migration): int
    {
        return $migration->asCommand($this);
    }
}
