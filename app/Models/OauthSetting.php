<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * @property int $id
 * @property string $provider
 * @property bool $enabled
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string|null $redirect_uri
 * @property string|null $tenant
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|OauthSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|OauthSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|OauthSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder|OauthSetting whereClientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OauthSetting whereClientSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OauthSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OauthSetting whereEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OauthSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OauthSetting whereProvider($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OauthSetting whereRedirectUri($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OauthSetting whereTenant($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OauthSetting whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class OauthSetting extends Model
{
    use HasFactory;

    protected function clientSecret(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => empty($value) ? null : Crypt::decryptString($value),
            set: fn (?string $value) => empty($value) ? null : Crypt::encryptString($value),
        );
    }
}
