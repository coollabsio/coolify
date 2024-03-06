<?php

namespace App\Providers;

use App\Listeners\MaintenanceModeDisabledNotification;
use App\Listeners\MaintenanceModeEnabledNotification;
use Illuminate\Foundation\Events\MaintenanceModeDisabled;
use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        MaintenanceModeEnabled::class => [
            MaintenanceModeEnabledNotification::class,
        ],
        MaintenanceModeDisabled::class => [
            MaintenanceModeDisabledNotification::class,
        ],
        // Registered::class => [
        //     SendEmailVerificationNotification::class,
        // ],
        \SocialiteProviders\Manager\SocialiteWasCalled::class => [
            \SocialiteProviders\Azure\AzureExtendSocialite::class.'@handle',
        ],
    ];
    public function boot(): void
    {
        //
    }
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
