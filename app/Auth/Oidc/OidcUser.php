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
        $this->emailVerified = ($claims['email_verified'] ?? false) === true;

        return $this;
    }
}
