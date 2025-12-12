<?php

namespace App\Socialite\OpenIDConnect;

use SocialiteProviders\Manager\SocialiteWasCalled;

class OpenIDConnectExtendSocialite
{
    public function handle(SocialiteWasCalled $socialiteWasCalled): void
    {
        $socialiteWasCalled->extendSocialite('openid', Provider::class);
    }
}
