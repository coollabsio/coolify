<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;
use Throwable;

class S3Storage extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

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

    public function isHetzner()
    {
        return str($this->endpoint)->contains('your-objectstorage.com');
    }

    public function isDigitalOcean()
    {
        return str($this->endpoint)->contains('digitaloceanspaces.com');
    }

    public function testConnection(bool $shouldSave = false)
    {
        try {
            set_s3_target($this);
            Storage::disk('custom-s3')->files();
            $this->unusable_email_sent = false;
            $this->is_usable = true;
        } catch (Throwable $e) {
            $this->is_usable = false;
            if ($this->unusable_email_sent === false && is_transactional_emails_enabled()) {
                $mailMessage = new MailMessage;
                $mailMessage->subject('Coolify: S3 Storage Connection Error');
                $mailMessage->view('emails.s3-connection-error', ['name' => $this->name, 'reason' => $e->getMessage(), 'url' => route('storage.show', ['storage_uuid' => $this->uuid])]);
                $users = collect([]);
                $members = $this->team->members()->get();
                foreach ($members as $user) {
                    if ($user->isAdmin()) {
                        $users->push($user);
                    }
                }
                foreach ($users as $user) {
                    send_user_an_email($mailMessage, $user->email);
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

    protected function casts(): array
    {
        return [
            'is_usable' => 'boolean',
            'key' => 'encrypted',
            'secret' => 'encrypted',
        ];
    }
}
