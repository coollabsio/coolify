<?php

namespace App\Services\ImageScan;

use App\Models\ImageScanIgnore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * What decides relevance for one resource (spec 15.2): its active ignores and the "Count medium problems" rule.
 */
final readonly class RelevanceRules
{
    public function __construct(
        public IgnoreMatcher $ignores,
        public bool $countMedium = false,
    ) {}

    public static function forResource(Model $resource): self
    {
        return new self(IgnoreMatcher::forResource($resource), ImageScanPolicyResolver::countMediumFor($resource));
    }

    /**
     * @param  Collection<int, ImageScanIgnore>  $ignores
     */
    public static function fromIgnores(Collection $ignores, bool $countMedium = false): self
    {
        return new self(IgnoreMatcher::fromIgnores($ignores), $countMedium);
    }

    /**
     * The same ignores with every medium counted. Version and base checks store the IDs they fix with these, so a
     * change of the rule needs no new check.
     */
    public function withAllMediums(): self
    {
        return new self($this->ignores, true);
    }
}
