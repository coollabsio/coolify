<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class CustomEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $backoff = [10, 20, 30, 40, 50];

    public $tries = 5;

    public $maxExceptions = 5;

    public function shouldDeduplicate(): bool
    {
        return true;
    }

    public function deduplicateFor(): int
    {
        return 900;
    }

    public function deduplicationKey(object $notifiable, string $channel): ?string
    {
        return null;
    }
}
