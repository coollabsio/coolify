<?php

namespace App\Listeners;

use App\Actions\User\SetupUserSessionAfterLogin as SetupUserSessionAfterLoginAction;
use Illuminate\Auth\Events\Login;

class SetupUserSessionAfterLogin
{
    public function handle(Login $event): void
    {
        SetupUserSessionAfterLoginAction::run($event->user);
    }
}
