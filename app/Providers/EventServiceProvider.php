<?php

namespace App\Providers;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use SocialiteProviders\Authentik\AuthentikExtendSocialite;
use SocialiteProviders\Azure\AzureExtendSocialite;
use SocialiteProviders\Clerk\ClerkExtendSocialite;
use SocialiteProviders\Discord\DiscordExtendSocialite;
use SocialiteProviders\Google\GoogleExtendSocialite;
use SocialiteProviders\Infomaniak\InfomaniakExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Zitadel\ZitadelExtendSocialite;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        SocialiteWasCalled::class => [
            AzureExtendSocialite::class.'@handle',
            AuthentikExtendSocialite::class.'@handle',
            ClerkExtendSocialite::class.'@handle',
            DiscordExtendSocialite::class.'@handle',
            GoogleExtendSocialite::class.'@handle',
            InfomaniakExtendSocialite::class.'@handle',
            ZitadelExtendSocialite::class.'@handle',
        ],
    ];

    public function boot(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            auditLog('auth.user.login_succeeded', $this->authContext($event->user));
        });
        Event::listen(Failed::class, function (Failed $event): void {
            auditLog('auth.user.login_failed', [
                'attempted_email' => data_get($event->credentials, 'email'),
                'guard' => $event->guard,
            ], 'warning');
        });
        Event::listen(Logout::class, function (Logout $event): void {
            auditLog('auth.user.logged_out', $this->authContext($event->user));
        });
        Event::listen(Registered::class, function (Registered $event): void {
            auditLog('auth.user.registered', $this->authContext($event->user));
        });
        Event::listen(Verified::class, function (Verified $event): void {
            auditLog('auth.user.email_verified', $this->authContext($event->user));
        });
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            auditLog('auth.user.password_reset', $this->authContext($event->user));
        });
    }

    private function authContext(?object $user): array
    {
        return [
            'team_id' => $user?->currentTeam()?->id,
            'resource' => 'user',
            'user_name' => $user?->name,
            'actor_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_email' => $user?->email,
        ];
    }

    public function shouldDiscoverEvents(): bool
    {
        return true;
    }
}
