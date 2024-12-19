<?php

namespace App\Jobs;

use App\Notifications\Dto\PushoverMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendMessageToPushoverJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 5;

    public $backoff = 10;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 5;

    public function __construct(
        public PushoverMessage $message,
        public string $token,
        public string $user,
    ) {
        $this->onQueue('high');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $response = Http::post('https://api.pushover.net/1/messages.json', $this->message->toPayload($this->token, $this->user));
        if ($response->failed()) {
            throw new \RuntimeException('Pushover notification failed with ' . $response->status() . ' status code.' . $response->body());
        }
    }
}
