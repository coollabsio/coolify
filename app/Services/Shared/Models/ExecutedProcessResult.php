<?php

namespace App\Services\Shared\Models;

class ExecutedProcessResult
{
    public function __construct(public readonly string $command, public readonly string $result) {}
}
