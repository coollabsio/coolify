<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Waits until a server that Coolify just created at a cloud provider is running and accepts SSH,
 * then validates it and installs Docker.
 */
class ValidateNewCloudServerJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_ATTEMPTS = 40;

    public const RETRY_DELAY_SECONDS = 30;

    public $tries = 1;

    public $timeout = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Server $server,
        public int $attempt = 1
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        if (! $this->server->canBeValidated() || $this->server->is_validating || $this->server->isFunctional()) {
            return;
        }

        if ($this->isReady()) {
            ValidateAndInstallServerJob::dispatch($this->server);

            return;
        }

        if ($this->attempt >= self::MAX_ATTEMPTS) {
            $this->server->update([
                'validation_logs' => 'The server did not start or accept SSH connections within '.(self::MAX_ATTEMPTS * self::RETRY_DELAY_SECONDS / 60).' minutes after it was created. Validate it manually.',
            ]);

            return;
        }

        self::dispatch($this->server, $this->attempt + 1)->delay(now()->addSeconds(self::RETRY_DELAY_SECONDS));
    }

    private function isReady(): bool
    {
        try {
            $status = $this->server->refreshCloudProviderState();
        } catch (\Throwable) {
            return false;
        }

        if (! in_array($status, ['running', 'active'], true) || $this->server->hasPlaceholderIp()) {
            return false;
        }

        return $this->server->validateConnection()['uptime'];
    }
}
