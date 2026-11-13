<?php

namespace App\Jobs;

use App\Models\EnvironmentConfigRevision;
use App\Services\ProjectConfig\ConfigApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApplyEnvironmentConfigJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public EnvironmentConfigRevision $revision)
    {
        $this->onQueue('high');
    }

    public function handle(ConfigApplier $applier): void
    {
        $applier->apply($this->revision);
    }
}
