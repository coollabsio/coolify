<?php

use App\Models\NotificationHistory;
use App\Models\Team;
use App\Notifications\Internal\GeneralNotification;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;

function is_transactional_emails_enabled(): bool
{
    $settings = instanceSettings();

    return $settings->smtp_enabled || $settings->resend_enabled;
}

function send_internal_notification(string $message): void
{
    try {
        $team = Team::find(0);
        $team?->notify(new GeneralNotification($message));
    } catch (\Throwable $e) {
        ray($e->getMessage());
    }
}

function send_user_an_email(MailMessage $mail, string $email, ?string $cc = null): void
{
    $settings = instanceSettings();
    $type = set_transanctional_email_settings($settings);
    if (blank($type)) {
        throw new Exception('No email settings found.');
    }
    if ($cc) {
        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->to($email)
                ->replyTo($email)
                ->cc($cc)
                ->subject($mail->subject)
                ->html((string) $mail->render())
        );
    } else {
        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->to($email)
                ->subject($mail->subject)
                ->html((string) $mail->render())
        );
    }
}

function set_transanctional_email_settings($settings = null)
{
    if (! $settings) {
        $settings = instanceSettings();
    }
    if (! data_get($settings, 'smtp_enabled') && ! data_get($settings, 'resend_enabled')) {
        return null;
    }

    $configRepository = app('App\Services\ConfigurationRepository'::class);
    $configRepository->updateMailConfig($settings);

    if (data_get($settings, 'resend_enabled')) {
        return 'resend';
    }

    if (data_get($settings, 'smtp_enabled')) {
        return 'smtp';
    }

    return null;
}

/**
 * Store a notification in the history table
 */
function store_notification_history(
    Team $team,
    string $notificationType,
    string $eventType,
    string $channel,
    ?string $title = null,
    ?string $message = null,
    ?array $metadata = null
): void {
    try {
        NotificationHistory::create([
            'team_id' => $team->id,
            'notification_type' => $notificationType,
            'event_type' => $eventType,
            'channel' => $channel,
            'title' => $title,
            'message' => $message,
            'metadata' => $metadata,
            'sent_at' => now(),
        ]);
    } catch (\Throwable $e) {
        // Silently fail - don't break notification sending if history storage fails
        if (isDev()) {
            ray('Failed to store notification history: '.$e->getMessage());
        }
    }
}
