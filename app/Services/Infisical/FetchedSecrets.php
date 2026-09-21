<?php

namespace App\Services\Infisical;

/**
 * The result of reading one Infisical folder.
 *
 * Hidden keys are reported rather than thrown so that one unreadable secret
 * does not block every other secret in the folder. A hidden key means the
 * machine identity lacks `secrets:readValue` on it.
 */
readonly class FetchedSecrets
{
    /**
     * @param  array<string, string>  $values
     * @param  array<int, string>  $hiddenKeys
     */
    public function __construct(
        public array $values = [],
        public array $hiddenKeys = [],
    ) {}
}
