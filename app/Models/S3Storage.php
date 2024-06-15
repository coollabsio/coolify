<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property string $region
 * @property mixed $key
 * @property mixed $secret
 * @property string $bucket
 * @property string|null $endpoint
 * @property int $team_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property bool $is_usable
 * @property bool $unusable_email_sent
 * @property-read \App\Models\Team|null $team
 *
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage query()
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage whereBucket($value)
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage whereEndpoint($value)
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage whereIsUsable($value)
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage whereRegion($value)
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage whereSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage whereUnusableEmailSent($value)
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|S3Storage whereUuid($value)
 *
 * @mixin \Eloquent
 */
class S3Storage extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_usable' => 'boolean',
        'key' => 'encrypted',
        'secret' => 'encrypted',
    ];

    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $selectArray = collect($select)->concat(['id']);

        return S3Storage::whereTeamId(currentTeam()->id)->select($selectArray->all())->orderBy('name');
    }

    public function isUsable()
    {
        return $this->is_usable;
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function awsUrl()
    {
        return "{$this->endpoint}/{$this->bucket}";
    }

    public function testConnection(bool $shouldSave = false)
    {
        try {
            set_s3_target($this);
            Storage::disk('custom-s3')->files();
            $this->unusable_email_sent = false;
            $this->is_usable = true;
        } catch (\Throwable $e) {
            $this->is_usable = false;
            if ($this->unusable_email_sent === false && is_transactional_emails_active()) {
                $mail = new MailMessage();
                $mail->subject('Coolify: S3 Storage Connection Error');
                $mail->view('emails.s3-connection-error', ['name' => $this->name, 'reason' => $e->getMessage(), 'url' => route('storage.show', ['storage_uuid' => $this->uuid])]);
                $users = collect([]);
                $members = $this->team->members()->get();
                foreach ($members as $user) {
                    if ($user->isAdmin()) {
                        $users->push($user);
                    }
                }
                foreach ($users as $user) {
                    send_user_an_email($mail, $user->email);
                }
                $this->unusable_email_sent = true;
            }

            throw $e;
        } finally {
            if ($shouldSave) {
                $this->save();
            }
        }
    }
}
