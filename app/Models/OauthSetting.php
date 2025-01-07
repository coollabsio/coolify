<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class OauthSetting extends Model
{
    use HasFactory;

    protected $fillable = ['provider', 'client_id', 'client_secret', 'redirect_uri', 'tenant', 'base_url', 'enabled'];

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
                return filled($this->client_id) && filled($this->client_secret) && filled($this->redirect_uri) && filled($this->tenant);
            case 'authentik':
                return filled($this->client_id) && filled($this->client_secret) && filled($this->redirect_uri) && filled($this->base_url);
            default:
                return filled($this->client_id) && filled($this->client_secret) && filled($this->redirect_uri);
        }
    }
}
