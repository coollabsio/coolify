<?php

namespace App\Models;

use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;

class S3Storage extends BaseModel
{
    use HasFactory, HasSafeStringAttribute;

    protected $guarded = [];

    protected $casts = [
        'is_usable' => 'boolean',
        'key' => 'encrypted',
        'secret' => 'encrypted',
    ];

    /**
     * Boot the model and register event listeners.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Trim whitespace from credentials before saving to prevent
        // "Malformed Access Key Id" errors from accidental whitespace in pasted values.
        // Note: We use the saving event instead of Attribute mutators because key/secret
        // use Laravel's 'encrypted' cast. Attribute mutators fire before casts, which
        // would cause issues with the encryption/decryption cycle.
        static::saving(function (S3Storage $storage) {
            if ($storage->key !== null) {
                $storage->key = trim($storage->key);
            }
            if ($storage->secret !== null) {
                $storage->secret = trim($storage->secret);
            }
        });
    }

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

    protected function path(): Attribute
    {
        return Attribute::make(
            set: function (?string $value) {
                if ($value === null || $value === '') {
                    return null;
                }

                return str($value)->trim()->start('/')->value();
            }
        );
    }

    /**
     * Trim whitespace from endpoint to prevent malformed URLs.
     */
    protected function endpoint(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value ? trim($value) : null,
        );
    }

    /**
     * Trim whitespace from bucket name to prevent connection errors.
     */
    protected function bucket(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value ? trim($value) : null,
        );
    }

    /**
     * Trim whitespace from region to prevent connection errors.
     */
    protected function region(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value ? trim($value) : null,
        );
    }

    public function testConnection(bool $shouldSave = false)
    {
        try {
            $disk = Storage::build([
                'driver' => 's3',
                'region' => $this['region'],
                'key' => $this['key'],
                'secret' => $this['secret'],
                'bucket' => $this['bucket'],
                'endpoint' => $this['endpoint'],
                'use_path_style_endpoint' => true,
            ]);
            // Test the connection by listing files with ListObjectsV2 (S3)
            $disk->files();

            $this->unusable_email_sent = false;
            $this->is_usable = true;
        } catch (\Throwable $e) {
            $this->is_usable = false;
            if ($this->unusable_email_sent === false && is_transactional_emails_enabled()) {
                $mail = new MailMessage;
                $mail->subject('Coolify: S3 Storage Connection Error');
                $mail->view('emails.s3-connection-error', ['name' => $this->name, 'reason' => $e->getMessage(), 'url' => route('storage.show', ['storage_uuid' => $this->uuid])]);

                // Load the team with its members and their roles explicitly
                $team = $this->team()->with(['members' => function ($query) {
                    $query->withPivot('role');
                }])->first();

                // Get admins directly from the pivot relationship for this specific team
                $users = $team->members()->wherePivotIn('role', ['admin', 'owner'])->get(['users.id', 'users.email']);
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
