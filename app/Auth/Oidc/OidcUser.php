<?php

namespace App\Auth\Oidc;

use Laravel\Socialite\Two\User as SocialiteUser;

class OidcUser extends SocialiteUser
{
    public ?string $issuer = null;

    public ?string $subject = null;

    public bool $emailVerified = false;

    /**
     * @var array<string, mixed>
     */
    public array $idTokenClaims = [];

    /**
     * @param  array<string, mixed>  $claims
     */
    public function setIdTokenClaims(array $claims): self
    {
        $this->idTokenClaims = $claims;
        $this->issuer = is_string($claims['iss'] ?? null) ? $claims['iss'] : null;
        $this->subject = is_string($claims['sub'] ?? null) ? $claims['sub'] : null;
        $this->emailVerified = self::claimsVerifyEmail($claims);

        return $this;
    }

    /**
     * Microsoft Entra ID never sends email_verified. Its optional xms_edov
     * claim ("email domain owner verified") is the documented replacement.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function claimsVerifyEmail(array $claims): bool
    {
        return ($claims['email_verified'] ?? null) === true || ($claims['xms_edov'] ?? null) === true;
    }
}
