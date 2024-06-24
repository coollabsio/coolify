<?php

namespace App\Models;

use App\Notifications\Channels\SendsEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Spatie\Url\Url;

/**
 * @property int $id
 * @property string|null $public_ipv4
 * @property string|null $public_ipv6
 * @property-write string|null $fqdn
 * @property int $public_port_min
 * @property int $public_port_max
 * @property bool $do_not_track
 * @property bool $is_auto_update_enabled
 * @property bool $is_registration_enabled
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property bool $next_channel
 * @property bool $is_resale_license_active
 * @property mixed|null $resale_license
 * @property bool $smtp_enabled
 * @property string|null $smtp_from_address
 * @property string|null $smtp_from_name
 * @property string|null $smtp_recipients
 * @property string|null $smtp_host
 * @property int|null $smtp_port
 * @property string|null $smtp_encryption
 * @property string|null $smtp_username
 * @property mixed|null $smtp_password
 * @property int|null $smtp_timeout
 * @property bool $resend_enabled
 * @property string|null $resend_api_key
 * @property bool $is_dns_validation_enabled
 * @property string|null $custom_dns_servers
 * @property bool $experimental_deployments
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings query()
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereCustomDnsServers($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereDoNotTrack($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereExperimentalDeployments($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereFqdn($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereIsAutoUpdateEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereIsDnsValidationEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereIsRegistrationEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereIsResaleLicenseActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereNextChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings wherePublicIpv4($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings wherePublicIpv6($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings wherePublicPortMax($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings wherePublicPortMin($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereResaleLicense($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereResendApiKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereResendEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereSmtpEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereSmtpEncryption($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereSmtpFromAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereSmtpFromName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereSmtpHost($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereSmtpPassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereSmtpPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereSmtpRecipients($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereSmtpTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereSmtpUsername($value)
 * @method static \Illuminate\Database\Eloquent\Builder|InstanceSettings whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class InstanceSettings extends Model implements SendsEmail
{
    use HasFactory, Notifiable;

    protected $guarded = [];

    protected $casts = [
        'resale_license' => 'encrypted',
        'smtp_password' => 'encrypted',
    ];

    public function fqdn(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value) {
                    $url = Url::fromString($value);
                    $host = $url->getHost();

                    return $url->getScheme().'://'.$host;
                }
            }
        );
    }

    public static function get()
    {
        return InstanceSettings::findOrFail(0);
    }

    public function getRecepients($notification)
    {
        $recipients = data_get($notification, 'emails', null);
        if (is_null($recipients) || $recipients === '') {
            return [];
        }

        return explode(',', $recipients);
    }

    public function getTitleDisplayName(): string
    {
        $instanceName = $this->instance_name;
        if (! $instanceName) {
            return '';
        }

        return "[{$instanceName}]";
    }
}
