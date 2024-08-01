<?php

namespace App\Notifications\TransactionalEmails;

use App\Models\InstanceSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPassword extends Notification
{
    public static $createUrlCallback;

    public static $toMailCallback;

    public $token;

    public InstanceSettings $settings;

    public function __construct($token)
    {
        $this->settings = \App\Models\InstanceSettings::get();
        $this->token = $token;
    }

    public static function createUrlUsing($callback)
    {
        static::$createUrlCallback = $callback;
    }

    public static function toMailUsing($callback)
    {
        static::$toMailCallback = $callback;
    }

    public function via($notifiable)
    {
        $type = set_transanctional_email_settings();
        if (! $type) {
            throw new \Exception('No email settings found.');
        }

        return ['mail'];
    }

    public function toMail($notifiable)
    {
        if (static::$toMailCallback) {
            return call_user_func(static::$toMailCallback, $notifiable, $this->token);
        }

        return $this->buildMailMessage($this->resetUrl($notifiable));
    }

    protected function buildMailMessage($url)
    {
        $mail = new MailMessage;
        $mail->subject('Coolify: Reset Password');
        $mail->view('emails.reset-password', ['url' => $url, 'count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]);

        return $mail;
    }

    protected function resetUrl($notifiable)
    {
        if (static::$createUrlCallback) {
            return call_user_func(static::$createUrlCallback, $notifiable, $this->token);
        }

        return url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
    }
}
