<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class OauthSetting extends Model
{
    use HasFactory;

    protected $fillable = ['provider', 'client_id', 'client_secret', 'redirect_uri', 'tenant', 'base_url', 'enabled', 'custom_label'];

    protected function clientSecret(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => empty($value) ? null : Crypt::decryptString($value),
            set: fn (?string $value) => empty($value) ? null : Crypt::encryptString($value),
        );
    }

    public function couldBeEnabled(): bool
    {
        switch ($this->provider) {
            case 'azure':
                return filled($this->client_id) && filled($this->client_secret) && filled($this->tenant);
            case 'authentik':
            case 'clerk':
            case 'openid':
                return filled($this->client_id) && filled($this->client_secret) && filled($this->base_url);
            default:
                return filled($this->client_id) && filled($this->client_secret);
        }
    }

    /**
     * Get the display label for the login button.
     * Priority: database custom_label > env custom_label > translation
     */
    public function getLoginLabel(): string
    {
        if (filled($this->custom_label)) {
            return $this->custom_label;
        }

        $envLabel = config("services.{$this->provider}.custom_label");
        if (filled($envLabel)) {
            return $envLabel;
        }

        return __("auth.login.{$this->provider}");
    }
}
