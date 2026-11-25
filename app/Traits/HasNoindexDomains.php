<?php

namespace App\Traits;

use App\Support\DomainPortOverrides;
use App\Support\ValidationPatterns;
use Illuminate\Support\Collection;

/**
 * Flags the subset of a resource's `fqdn` domains that must not be indexed.
 *
 * Domains are normalized on write and on compare, so a casing difference
 * between the stored flag and the configured domain cannot silently drop it.
 *
 * @property array<int, string>|null $noindex_domains
 */
trait HasNoindexDomains
{
    public function noindexDomains(): Collection
    {
        return collect($this->noindex_domains ?? [])
            ->filter(fn ($domain) => is_string($domain) && filled($domain))
            ->map(fn (string $domain) => $this->normalizeNoindexDomain($domain))
            ->unique()
            ->values();
    }

    public function isDomainNoindexed(string $domain): bool
    {
        return $this->noindexDomains()->contains(
            $this->normalizeNoindexDomain($domain)
        );
    }

    public function setNoindexDomains(iterable $domains): void
    {
        $this->noindex_domains = collect($domains)
            ->filter(fn ($domain) => is_string($domain) && filled($domain))
            ->map(fn (string $domain) => $this->normalizeNoindexDomain($domain))
            ->intersect($this->currentDomains())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Drops flags for domains the resource no longer has. Without this, re-adding
     * a removed domain later would silently resurrect its old flag.
     */
    public function syncNoindexDomains(): void
    {
        if (blank($this->noindex_domains)) {
            return;
        }

        $this->setNoindexDomains($this->noindex_domains);
    }

    private function currentDomains(): Collection
    {
        $domains = collect(ValidationPatterns::applicationDomainList($this->fqdn));
        $composeDomains = json_decode((string) ($this->getAttributes()['docker_compose_domains'] ?? null), true);

        if (is_array($composeDomains)) {
            foreach ($composeDomains as $entry) {
                $domains->push(...ValidationPatterns::applicationDomainList(composeDomainEntryString($entry)));
            }
        }

        return $domains
            ->map(fn (string $domain) => $this->normalizeNoindexDomain($domain));
    }

    private function normalizeNoindexDomain(string $domain): string
    {
        return DomainPortOverrides::withoutPort(
            ValidationPatterns::normalizeApplicationDomainUrl($domain)
        );
    }
}
