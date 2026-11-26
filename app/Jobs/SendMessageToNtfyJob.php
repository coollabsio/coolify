<?php

namespace App\Jobs;

use App\Notifications\Dto\NtfyMessage;
use App\Rules\SafeWebhookUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SendMessageToNtfyJob implements ShouldBeEncrypted, ShouldQueue
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
        public NtfyMessage $message,
        public string $ntfyUrl,
        public string $ntfyTopic,
        public ?string $ntfyToken = null,
        public ?string $ntfyUsername = null,
        public ?string $ntfyPassword = null,
    ) {
        $this->onQueue('high');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $validator = Validator::make(
            ['ntfy_url' => $this->ntfyUrl],
            ['ntfy_url' => ['required', 'url', new SafeWebhookUrl]]
        );

        if ($validator->fails()) {
            Log::warning('SendMessageToNtfyJob: blocked unsafe ntfy server URL', [
                'url' => SafeWebhookUrl::redactedUrlForLog($this->ntfyUrl),
                'errors' => $validator->errors()->all(),
            ]);

            return;
        }

        try {
            $httpOptions = SafeWebhookUrl::httpClientOptions($this->ntfyUrl);
        } catch (\RuntimeException $e) {
            Log::warning('SendMessageToNtfyJob: blocked unsafe ntfy server URL at send time', [
                'url' => SafeWebhookUrl::redactedUrlForLog($this->ntfyUrl),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $payload = $this->message->toPayload($this->ntfyTopic);
        $url = rtrim($this->ntfyUrl, '/');

        $request = Http::withOptions($httpOptions)->asJson();

        if ($this->ntfyToken) {
            $request = $request->withToken($this->ntfyToken);
        } elseif ($this->ntfyUsername && $this->ntfyPassword) {
            $request = $request->withBasicAuth($this->ntfyUsername, $this->ntfyPassword);
        }

        $response = $request->post($url, $payload);

        if ($response->failed()) {
            // The response body can echo credentials or the message, so keep it out of failed job records.
            throw new \RuntimeException('Ntfy notification failed with '.$response->status().' status code.');
        }
    }
}
