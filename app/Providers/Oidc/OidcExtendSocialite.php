<?php

namespace App\Providers\Oidc;

use SocialiteProviders\Manager\SocialiteWasCalled;

class OidcExtendSocialite
{
    /**
     * Register the provider.
     *
     * @param \SocialiteProviders\Manager\SocialiteWasCalled $socialiteWasCalled
     */
    public function handle(SocialiteWasCalled $socialiteWasCalled)
    {
        $socialiteWasCalled->extendSocialite('oidc', OidcProvider::class);
    }
}
