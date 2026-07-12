<?php

namespace App\Traits;

use App\Support\ValidationPatterns;
use Illuminate\Support\Collection;

/**
 * Marks individual domains of a resource as non-indexable.
 *
 * The resource keeps its domains in the comma-separated `fqdn` column; this
 * trait stores the subset of those domains that should be served with an
 * `X-Robots-Tag: noindex, nofollow` response header, so a production domain and
 * an auto-generated technical domain on the same resource can differ.
 *
 * Domains are stored and compared in their normalized form so that casing
 * differences between what the user typed and what is persisted cannot cause a
 * flag to be silently ignored.
 */
trait HasNoindexDomains
{
    /**
     * The domains of this resource that must not be indexed.
     *
     * @return Collection<int, string>
     */
    public function noindexDomains(): Collection
    {
        return collect($this->noindex_domains ?? [])
            ->filter(fn ($domain) => is_string($domain) && filled($domain))
            ->map(fn (string $domain) => ValidationPatterns::normalizeApplicationDomainUrl($domain))
            ->unique()
            ->values();
    }

    public function isDomainNoindexed(string $domain): bool
    {
        return $this->noindexDomains()->contains(
            ValidationPatterns::normalizeApplicationDomainUrl($domain)
        );
    }

    /**
     * Replace the noindex list, keeping only domains this resource actually has.
     *
     * @param  iterable<int, string>  $domains
     */
    public function setNoindexDomains(iterable $domains): void
    {
        $this->noindex_domains = collect($domains)
            ->filter(fn ($domain) => is_string($domain) && filled($domain))
            ->map(fn (string $domain) => ValidationPatterns::normalizeApplicationDomainUrl($domain))
            ->intersect($this->currentDomains())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Drop flags for domains that are no longer configured on this resource.
     *
     * Without this, renaming or removing a domain would leave its flag behind,
     * and re-adding the same domain later would silently resurrect it.
     */
    public function syncNoindexDomains(): void
    {
        if (blank($this->noindex_domains)) {
            return;
        }

        $this->setNoindexDomains($this->noindex_domains);
    }

    /**
     * The resource's configured domains, normalized.
     *
     * @return Collection<int, string>
     */
    private function currentDomains(): Collection
    {
        return collect(ValidationPatterns::applicationDomainList($this->fqdn))
            ->map(fn (string $domain) => ValidationPatterns::normalizeApplicationDomainUrl($domain));
    }
}
