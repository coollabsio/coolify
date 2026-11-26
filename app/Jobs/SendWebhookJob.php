<?php

namespace App\Jobs;

use App\Models\WebhookNotificationSettings;
use App\Rules\SafeWebhookUrl;
use App\Traits\HandlesNotificationResponse;
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
    use Dispatchable, HandlesNotificationResponse, InteractsWithQueue, Queueable, SerializesModels;

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
        public string $webhookUrl,
        public ?WebhookNotificationSettings $settings = null,
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
            ['webhook_url' => ['required', 'url', new SafeWebhookUrl]]
        );

        if ($validator->fails()) {
            Log::warning('SendWebhookJob: blocked unsafe webhook URL', [
                'url' => SafeWebhookUrl::redactedUrlForLog($this->webhookUrl),
                'errors' => $validator->errors()->all(),
            ]);

            return;
        }

        try {
            $httpOptions = SafeWebhookUrl::httpClientOptions($this->webhookUrl);
        } catch (\RuntimeException $e) {
            Log::warning('SendWebhookJob: blocked unsafe webhook URL at send time', [
                'url' => SafeWebhookUrl::redactedUrlForLog($this->webhookUrl),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $response = $this->sendNotificationRequest('Webhook', $this->webhookUrl, fn () => Http::withOptions($httpOptions)->post($this->webhookUrl, $this->payload));

        // 401/403: the endpoint rejects us, 404/410: the endpoint does not exist anymore.
        $this->handleNotificationResponse($response, 'Webhook', in_array($response->status(), [401, 403, 404, 410], true));
    }
}
