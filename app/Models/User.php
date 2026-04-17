<?php

namespace App\Models;

use App\Jobs\UpdateStripeCustomerEmailJob;
use App\Notifications\Channels\SendsEmail;
use App\Notifications\TransactionalEmails\EmailChangeVerification;
use App\Notifications\TransactionalEmails\ResetPassword as TransactionalEmailsResetPassword;
use App\Services\ChangelogService;
use App\Traits\DeletesUserSessions;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;
use OpenApi\Attributes as OA;

#[OA\Schema(
        description: 'User model',
        type: 'object',
        properties: [
            'id' => ['type' => 'integer', 'description' => 'The user identifier in the database.'],
            'name' => ['type' => 'string', 'description' => 'The user name.'],
            'email' => ['type' => 'string', 'description' => 'The user email.'],
            'email_verified_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'The date and time when the email was verified.'],
            'two_factor_confirmed_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'The date and time when the two-factor authentication was confirmed.'],
            'force_password_reset' => ['type' => 'boolean', 'description' => 'If true, the user is forced to reset their password.'],
            'force_oauth_login' => ['type' => 'boolean', 'description' => 'If true, the user is forced to login via OAuth.'],
            'created_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'The date and time when the user was created.'],
            'updated_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'The date and time when the user was updated.'],
        ]
    )]
    class User extends Authenticatable implements SendsEmail
    {
            use DeletesUserSessions;
            use HasApiTokens;
            use HasFactory;
            use Notifiable;
            use TwoFactorAuthenticatable;

    protected $fillable = [
                'name',
                'email',
                'password',
                'email_verified_at',
                'force_password_reset',
                'force_oauth_login',
                'two_factor_confirmed_at',
                'marketing_emails',
            ];

    protected $hidden = [
                'password',
                'remember_token',
                'two_factor_recovery_codes',
                'two_factor_secret',
            ];

    protected $casts = [
