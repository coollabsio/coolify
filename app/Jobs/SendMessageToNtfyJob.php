<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

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

    /**
     * Create a new job instance.
     *
     * @param  string  $text  The message to send
     * @param  string|null  $buttons  The buttons to send. These buttons follow the format as described in the Ntfy documentation: https://docs.ntfy.sh/publish/?h=user#defining-actions
     * @param  string|null  $emoji  The emoji to use for the message. We use the shortcodes for emojis. A list of them can be found here: https://docs.ntfy.sh/emojis/
     * @param  string|null  $title  The title of the message
     * @param  string  $url  The URL of the Ntfy instance
     * @param  string|null  $username  The username to use for basic authentication
     * @param  string|null  $password  The password to use for basic authentication
     * @param  string  $topic  The topic to send the message to
     */
    public function __construct(
        public string $text,
        public ?string $buttons,
        public ?string $emoji,
        public ?string $title,
        public string $url,
        public ?string $username,
        public ?string $password,
        public string $topic
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {

        $headers = [];
        if ($this->username && $this->password) {
            $headers['Authorization'] = 'Basic '.base64_encode($this->username.':'.$this->password);
        }

        if ($this->buttons) {
            $headers['Actions'] = $this->buttons;
        }

        $headers['Content-Type'] = 'text/markdown';
        $headers['Tags'] = 'coolify';

        if ($this->emoji) {
            $headers['Tags'] .= ','.$this->emoji;
        }

        if ($this->title) {
            $headers['Title'] = $this->title;
        }

        $payload = $this->text;
        Http::withHeaders($headers)->post($this->url.'/'.$this->topic, $payload);
    }
}
