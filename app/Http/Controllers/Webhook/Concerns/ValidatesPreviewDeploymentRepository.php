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
        $sourceRepository = $this->normalizeRepositoryIdentity($sourceRepository);
        $targetRepository = $this->normalizeRepositoryIdentity($targetRepository);
        $webhookRepository = $this->normalizeRepositoryIdentity($webhookRepository);

        if ($sourceRepository === null || $targetRepository === null || $webhookRepository === null) {
            return false;
        }

        if (! hash_equals($targetRepository, $webhookRepository)) {
            return false;
        }

        return hash_equals($sourceRepository, $targetRepository) || $publicPreviewsEnabled;
    }

    /**
     * A pull request comes from a fork when its source repository differs from the target.
     * A missing or malformed repository id counts as a fork, so the commit needs approval.
     */
    protected function isForkPreviewRepository(mixed $sourceRepository, mixed $targetRepository): bool
    {
        $sourceRepository = $this->normalizeRepositoryIdentity($sourceRepository);
        $targetRepository = $this->normalizeRepositoryIdentity($targetRepository);

        if ($sourceRepository === null || $targetRepository === null) {
            return true;
        }

        return ! hash_equals($targetRepository, $sourceRepository);
    }

    /**
     * Providers send repository ids as integers (GitLab, Gitea) or strings (Bitbucket UUIDs).
     * Any other type, such as a boolean or a float, is malformed and gives null.
     */
    private function normalizeRepositoryIdentity(mixed $identity): ?string
    {
        if (! is_int($identity) && ! is_string($identity)) {
            return null;
        }

        $identity = trim((string) $identity);

        return $identity === '' ? null : $identity;
    }
}
