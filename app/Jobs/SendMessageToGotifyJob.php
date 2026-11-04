<?php

namespace App\Jobs;

use App\Models\GotifyNotificationSettings;
use App\Notifications\Dto\GotifyMessage;
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

class SendMessageToGotifyJob implements ShouldBeEncrypted, ShouldQueue
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
        public GotifyMessage $message,
        public string $url,
        public string $token,
        public ?GotifyNotificationSettings $settings = null,
    ) {
        $this->onQueue('high');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $endpoint = rtrim($this->url, '/').'/message';
        $validator = Validator::make(
            ['gotify_url' => $this->url],
            ['gotify_url' => ['required', 'url', new SafeWebhookUrl]]
        );

        if ($validator->fails()) {
            Log::warning('SendMessageToGotifyJob: blocked unsafe Gotify URL', [
                'url' => SafeWebhookUrl::redactedUrlForLog($this->url),
                'errors' => $validator->errors()->all(),
            ]);

            return;
        }

        try {
            $httpOptions = SafeWebhookUrl::httpClientOptions($endpoint);
        } catch (\RuntimeException $e) {
            Log::warning('SendMessageToGotifyJob: blocked unsafe Gotify URL at send time', [
                'url' => SafeWebhookUrl::redactedUrlForLog($endpoint),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $response = $this->sendNotificationRequest('Gotify', $endpoint, fn () => Http::withOptions($httpOptions)
            ->withHeaders(['X-Gotify-Key' => $this->token])
            ->post($endpoint, $this->message->toPayload()));

        // 401/403: the application token is rejected, 404/410: the server path does not exist.
        $this->handleNotificationResponse($response, 'Gotify', in_array($response->status(), [401, 403, 404, 410], true));
    }
}
