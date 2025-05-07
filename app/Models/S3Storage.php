<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;

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
