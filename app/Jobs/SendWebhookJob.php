<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SendWebhookJob implements ShouldBeEncrypted, ShouldQueue
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
        public array $payload,
        public string $webhookUrl
    ) {
        $this->onQueue('high');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $validator = Validator::make(
            ['webhook_url' => $this->webhookUrl],
            ['webhook_url' => ['required', 'url', new \App\Rules\SafeWebhookUrl]]
        );

        if ($validator->fails()) {
            Log::warning('SendWebhookJob: blocked unsafe webhook URL', [
                'url' => $this->webhookUrl,
                'errors' => $validator->errors()->all(),
            ]);

            return;
        }

        if (isDev()) {
            ray('Sending webhook notification', [
                'url' => $this->webhookUrl,
                'payload' => $this->payload,
            ]);
        }

        $response = Http::post($this->webhookUrl, $this->payload);

        if (isDev()) {
            ray('Webhook response', [
                'status' => $response->status(),
                'body' => $response->body(),
                'successful' => $response->successful(),
            ]);
        }
    }
}
