<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SendMessageToPushoverJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 5;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    public function __construct(
        public string $text,
        public array $buttons,
        public string $token,
        public string $user,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $message = '<p>'.$this->text.'</p>';

        if (! empty($this->buttons)) {
            foreach ($this->buttons as $button) {
                $buttonUrl = data_get($button, 'url');
                $text = data_get($button, 'text', 'Click here');
                if ($buttonUrl && Str::contains($buttonUrl, 'http://localhost')) {
                    $buttonUrl = str_replace('http://localhost', config('app.url'), $buttonUrl);
                }
                $message .= "&nbsp;<a href='".$buttonUrl."'>".$text.'</a>';
            }
        }

        $payload = [
            'token' => $this->token,
            'user' => $this->user,
            'message' => $message,
            'html' => 1,
        ];
        ray($payload);
        Http::post('https://api.pushover.net/1/messages.json', $payload);
    }
}
