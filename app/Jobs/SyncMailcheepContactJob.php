<?php

namespace App\Jobs;

use App\Models\Team;
use App\Services\MailcheepService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncMailcheepContactJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * @param  array<string, string>  $customFields
     */
    public function __construct(
        public string $action,
        public string $email,
        public string $name,
        public array $customFields = [],
    ) {
        $this->onQueue('high');
    }

    public function handle(MailcheepService $mailcheep): void
    {
        match ($this->action) {
            'create_or_update' => $mailcheep->createOrUpdateContact(
                $this->email,
                $this->name,
                config('subscription.mailcheep_list_subscribed'),
                $this->customFields,
            ),
            'add_to_churned' => $mailcheep->addToChurnedList($this->email),
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $extraFields
     */
    public static function dispatchForTeam(Team $team, string $action, array $extraFields = []): void
    {
        if (! config('subscription.mailcheep_api_key')) {
            return;
        }

        $owner = $team->members->firstWhere('pivot.role', 'owner');

        if (! $owner) {
            return;
        }

        static::dispatch(
            action: $action,
            email: $owner->email,
            name: $owner->name,
            customFields: $extraFields,
        );
    }
}
