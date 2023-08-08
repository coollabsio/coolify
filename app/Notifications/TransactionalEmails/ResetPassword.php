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
        $this->settings = InstanceSettings::get();
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
        if ($this->settings->smtp_enabled) {
            $password = data_get($this->settings, 'smtp_password');
            if ($password) $password = decrypt($password);

            config()->set('mail.default', 'smtp');
            config()->set('mail.mailers.smtp', [
                "transport" => "smtp",
                "host" => data_get($this->settings, 'smtp_host'),
                "port" => data_get($this->settings, 'smtp_port'),
                "encryption" => data_get($this->settings, 'smtp_encryption'),
                "username" => data_get($this->settings, 'smtp_username'),
                "password" => $password,
                "timeout" => data_get($this->settings, 'smtp_timeout'),
                "local_domain" => null,
            ]);
            return ['mail'];
        }
        throw new \Exception('SMTP is not enabled');

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
        $mail = new MailMessage();
        $mail->from(
            data_get($this->settings, 'smtp_from_address'),
            data_get($this->settings, 'smtp_from_name'),
        );
        $mail->subject('Reset Password');
        $mail->view('emails.reset-password', ['url' => $url, 'count' => config('auth.passwords.' . config('auth.defaults.passwords') . '.expire')]);
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
