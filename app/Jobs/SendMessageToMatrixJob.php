<?php

namespace App\Jobs;

use App\Notifications\Dto\MatrixMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

class SendMessageToMatrixJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $maxExceptions = 3;

    /**
     * Exponential backoff delays for Matrix API rate limiting.
     * Matrix homeservers typically implement rate limiting,
     * so we use increasing delays to avoid hitting limits.
     */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function __construct(
        private MatrixMessage $message,
        private string $homeserverUrl,
        private string $roomId,
        private string $accessToken
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $transactionId = 'coolify_' . time() . '_' . rand(1000, 9999);
        $url = rtrim($this->homeserverUrl, '/') . "/_matrix/client/r0/rooms/{$this->roomId}/send/m.room.message/{$transactionId}";

        $body = [
            'msgtype' => 'm.text',
            'body' => "{$this->message->title}\n\n{$this->message->description}",
            'format' => 'org.matrix.custom.html',
            'formatted_body' => "<h3 style=\"color: {$this->message->color}\">{$this->message->title}</h3><p>{$this->message->description}</p>",
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ])->timeout(15)->put($url, $body);

            $response->throw();

            // Log successful delivery for monitoring
            logger()->info('Matrix notification sent successfully', [
                'room_id' => $this->roomId,
                'homeserver' => $this->homeserverUrl,
                'transaction_id' => $transactionId,
            ]);
        } catch (\Throwable $e) {
            // Enhanced error logging for Matrix API failures
            logger()->error('Matrix notification failed', [
                'room_id' => $this->roomId,
                'homeserver' => $this->homeserverUrl,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
                'status_code' => $e->getCode(),
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}