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

    /**
     * Whether a pull request is from a fork, with the identities of the v4 check: null when the
     * target repository is unknown or is not the repository of the webhook, so no preview deploys.
     * An unknown source repository, like a deleted fork, counts as a fork (decision 0025).
     */
    protected function isPreviewDeploymentFromFork(mixed $sourceRepository, mixed $targetRepository, mixed $webhookRepository): ?bool
    {
        if (! $this->isPreviewDeploymentRepositoryTrusted($targetRepository, $targetRepository, $webhookRepository, false)) {
            return null;
        }

        return ! $this->isPreviewDeploymentRepositoryTrusted($sourceRepository, $targetRepository, $webhookRepository, false);
    }
}
