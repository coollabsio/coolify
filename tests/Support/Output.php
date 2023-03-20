<?php

namespace Tests\Support;

use Illuminate\Support\Collection;

class Output
{
    public static function containerList($rawOutput): Collection
    {
        $outputLines = explode(PHP_EOL, $rawOutput);

        return collect($outputLines)
            ->reject(fn($line) => empty($line))
            ->map(fn($outputLine) => json_decode($outputLine, flags: JSON_THROW_ON_ERROR));
    }
}
