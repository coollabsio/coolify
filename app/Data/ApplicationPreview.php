<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class ApplicationPreview extends Data
{
    public function __construct(
        public int $pullRequestId,
        public string $branch,
        public ?string $commit,
    ) {
    }
}
