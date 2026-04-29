<?php

namespace App\Notifications\TransactionalEmails;

use App\Models\User;
use App\Notifications\Channels\TransactionalEmailChannel;
use App\Notifications\CustomEmailNotification;
use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;

class MaintenanceNotice extends CustomEmailNotification
{
    public bool $isTransactionalEmail = true;

    /**
     * @param  Carbon  $maintenanceAt  Maintenance window start (UTC).
     * @param  int  $durationMinutes  Planned duration of the maintenance window.
     * @param  bool|null  $hasInactiveTeamsOverride  When set, bypasses the DB check (used for test emails).
     */
    public function __construct(
        public User $user,
        public Carbon $maintenanceAt,
        public int $durationMinutes = 240,
        public ?bool $hasInactiveTeamsOverride = null,
    ) {
        $this->onQueue('high');
    }

    public function via(): array
    {
        return [TransactionalEmailChannel::class];
    }

    public function toMail(): MailMessage
    {
        $startUtc = $this->maintenanceAt->copy()->utc();
        $endUtc = $startUtc->copy()->addMinutes($this->durationMinutes);
        $startEu = $startUtc->copy()->setTimezone('Europe/Budapest');
        $endEu = $endUtc->copy()->setTimezone('Europe/Budapest');
        $daysFromNow = (int) round(now()->diffInDays($startUtc, false));

        $hasInactiveTeams = $this->hasInactiveTeamsOverride
            ?? (! $this->user->is_inactive
                && $this->user->teams()->where('teams.is_inactive', true)->exists());

        $mail = new MailMessage;
        $mail->subject('Scheduled Maintenance: Coolify Cloud Infrastructure');
        $mail->view('emails.maintenance-notice', [
            'isInactive' => (bool) $this->user->is_inactive,
            'hasInactiveTeams' => $hasInactiveTeams,
            'daysFromNow' => $daysFromNow,
            'startUtcLong' => $startUtc->format('F j, Y H:i').' UTC',
            'endUtcLong' => $endUtc->format('F j, Y H:i').' UTC',
            'startEu' => $startEu->format('H:i'),
            'endEu' => $endEu->format('H:i'),
            'euAbbr' => $startEu->format('T'),
            'durationMinutes' => $this->durationMinutes,
            'subscriptionUrl' => 'https://app.coolify.io/subscription',
        ]);

        return $mail;
    }
}
