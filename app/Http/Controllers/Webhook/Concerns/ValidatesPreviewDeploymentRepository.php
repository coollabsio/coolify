<?php

namespace App\Http\Controllers\Webhook\Concerns;

trait ValidatesPreviewDeploymentRepository
{
    protected function isPreviewDeploymentRepositoryTrusted(
        mixed $sourceRepository,
        mixed $targetRepository,
        mixed $webhookRepository,
        bool $publicPreviewsEnabled,
    ): bool {
        $identities = [$sourceRepository, $targetRepository, $webhookRepository];

        if (collect($identities)->contains(fn (mixed $identity): bool => ! is_scalar($identity) || trim((string) $identity) === '')) {
            return false;
        }

        $sourceRepository = trim((string) $sourceRepository);
        $targetRepository = trim((string) $targetRepository);
        $webhookRepository = trim((string) $webhookRepository);

        if (! hash_equals($targetRepository, $webhookRepository)) {
            return false;
        }

        return hash_equals($sourceRepository, $targetRepository) || $publicPreviewsEnabled;
    }
}
