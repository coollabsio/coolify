<?php

use App\Services\Auth\OauthIdentityIssuer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Non-OIDC identities were keyed by the provider name only, so after a
     * provider's base URL (or Azure tenant) pointed to another instance, a user
     * there with the same subject id signed in as the linked user. Identities
     * are now keyed by the provider instance (see OauthIdentityIssuer).
     *
     * Existing rows were created against the currently configured instance, so
     * they are rekeyed with the instance key of the current OAuth settings and
     * keep matching. Rows of self-hostable providers without a base URL cannot
     * be keyed and stay unchanged; they no longer match any login.
     *
     * Renaming the host of the same instance (or switching the Azure tenant
     * between its GUID and domain) changes the key. Linked users then no longer
     * match until an admin runs `php artisan oauth:rekey <provider> --from=<old>`.
     */
    public function up(): void
    {
        $oauthSettings = DB::table('oauth_settings')
            ->where('provider', '!=', 'oidc')
            ->orderBy('id')
            ->get(['provider', 'base_url', 'tenant'])
            ->unique('provider');

        foreach ($oauthSettings as $oauthSetting) {
            $issuer = OauthIdentityIssuer::forProvider($oauthSetting->provider, $oauthSetting->base_url, $oauthSetting->tenant);
            if ($issuer === null || $issuer === $oauthSetting->provider) {
                continue;
            }

            DB::table('oauth_identities')
                ->where('provider', $oauthSetting->provider)
                ->where('issuer', $oauthSetting->provider)
                ->update(['issuer' => $issuer]);
        }
    }

    public function down(): void
    {
        DB::table('oauth_identities')
            ->where('provider', '!=', 'oidc')
            ->update(['issuer' => DB::raw('provider')]);
    }
};
