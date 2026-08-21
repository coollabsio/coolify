<?php

namespace App\Actions\Migration;

use App\Enums\ResourceMigrationStatus;
use App\Models\ResourceMigrationItem;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class VerifyHealth
{
    use AsAction;

    public function handle(Model $resource, ResourceMigrationItem $item, int $attempts = 12, int $sleepSeconds = 5): void
    {
        $resource->refresh();
        $status = (string) $resource->status;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($this->isHealthy($status)) {
                $item->mark(ResourceMigrationStatus::Healthy);

                return;
            }

            if ($this->isFailed($status)) {
                $item->mark(ResourceMigrationStatus::Failed, "Resource status is {$status}.");

                return;
            }

            sleep($sleepSeconds);
            $resource->refresh();
            $status = (string) $resource->status;
        }

        if ($this->isHealthy($status)) {
            $item->mark(ResourceMigrationStatus::Healthy);

            return;
        }

        throw new RuntimeException("Timed out waiting for {$resource->uuid} to become healthy (status: {$status}).");
    }

    private function isHealthy(string $status): bool
    {
        $normalized = strtolower($status);

        return str_contains($normalized, 'healthy')
            || str_contains($normalized, 'running');
    }

    private function isFailed(string $status): bool
    {
        $normalized = strtolower($status);

        return str_contains($normalized, 'unhealthy')
            || str_contains($normalized, 'error')
            || str_contains($normalized, 'failed');
    }
}
