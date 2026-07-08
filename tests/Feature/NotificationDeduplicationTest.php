<?php

use App\Notifications\CustomEmailNotification;
use App\Services\NotificationDeduplicator;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Cache;

class DedupeTestNotification extends CustomEmailNotification
{
    public function __construct(
        public ?string $semanticKey = null,
        public bool $deduplicate = true,
        public int $ttl = 900,
    ) {}

    public function toMail(): MailMessage
    {
        return (new MailMessage)->subject('Test');
    }

    public function shouldDeduplicate(): bool
    {
        return $this->deduplicate;
    }

    public function deduplicateFor(): int
    {
        return $this->ttl;
    }

    public function deduplicationKey(object $notifiable, string $channel): ?string
    {
        return $this->semanticKey;
    }
}

beforeEach(function () {
    Cache::flush();
    $this->deduplicator = app(NotificationDeduplicator::class);
    $this->notifiable = new class
    {
        public int $id = 123;
    };
});

it('allows only the first identical notification fingerprint during the ttl', function () {
    $notification = new DedupeTestNotification;

    expect($this->deduplicator->shouldSend($this->notifiable, $notification, 'mail', ['first@example.com'], 'Subject', '<p>Body</p>'))->toBeTrue()
        ->and($this->deduplicator->shouldSend($this->notifiable, $notification, 'mail', ['first@example.com'], 'Subject', '<p>Body</p>'))->toBeFalse();
});

it('allows different recipients and content through the default fingerprint', function () {
    $notification = new DedupeTestNotification;

    expect($this->deduplicator->shouldSend($this->notifiable, $notification, 'mail', ['first@example.com'], 'Subject', '<p>Body</p>'))->toBeTrue()
        ->and($this->deduplicator->shouldSend($this->notifiable, $notification, 'mail', ['second@example.com'], 'Subject', '<p>Body</p>'))->toBeTrue()
        ->and($this->deduplicator->shouldSend($this->notifiable, $notification, 'mail', ['first@example.com'], 'Other subject', '<p>Body</p>'))->toBeTrue()
        ->and($this->deduplicator->shouldSend($this->notifiable, $notification, 'mail', ['first@example.com'], 'Subject', '<p>Other body</p>'))->toBeTrue();
});

it('uses semantic keys instead of rendered content when provided', function () {
    $notification = new DedupeTestNotification(semanticKey: 'event:123');

    expect($this->deduplicator->shouldSend($this->notifiable, $notification, 'mail', ['first@example.com'], 'Subject', '<p>Body</p>'))->toBeTrue()
        ->and($this->deduplicator->shouldSend($this->notifiable, $notification, 'mail', ['first@example.com'], 'Other subject', '<p>Other body</p>'))->toBeFalse()
        ->and($this->deduplicator->shouldSend($this->notifiable, $notification, 'mail', ['second@example.com'], 'Other subject', '<p>Other body</p>'))->toBeTrue();
});

it('allows notifications to opt out of deduplication', function () {
    $notification = new DedupeTestNotification(deduplicate: false);

    expect($this->deduplicator->shouldSend($this->notifiable, $notification, 'mail', ['first@example.com'], 'Subject', '<p>Body</p>'))->toBeTrue()
        ->and($this->deduplicator->shouldSend($this->notifiable, $notification, 'mail', ['first@example.com'], 'Subject', '<p>Body</p>'))->toBeTrue();
});
