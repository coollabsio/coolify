<?php

namespace App\Console\Commands;

use App\Jobs\SendConfirmationForWaitlistJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\ScheduledDatabaseBackup;
use App\Models\StandalonePostgresql;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\Waitlist;
use App\Notifications\Application\DeploymentFailed;
use App\Notifications\Application\DeploymentSuccess;
use App\Notifications\Application\StatusChanged;
use App\Notifications\Database\BackupFailed;
use App\Notifications\Database\BackupSuccess;
use App\Notifications\Test;
use App\Notifications\TransactionalEmails\InvitationLink;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Process;
use Mail;
use Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class TestEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a test email to the admin';

    /**
     * Execute the console command.
     */
    private ?MailMessage $mail = null;
    private string $email = 'andras.bacsai@protonmail.com';
    public function handle()
    {
        $type = select(
            'Which Email should be sent?',
            options: [
                'emails-test' => 'Test',
                'application-deployment-success' => 'Application - Deployment Success',
                'application-deployment-failed' => 'Application - Deployment Failed',
                'application-status-changed' => 'Application - Status Changed',
                'backup-success' => 'Database - Backup Success',
                'backup-failed' => 'Database - Backup Failed',
                'invitation-link' => 'Invitation Link',
                'waitlist-invitation-link' => 'Waitlist Invitation Link',
                'waitlist-confirmation' => 'Waitlist Confirmation',
            ],
        );
        $this->email = text('Email Address to send to');
        set_transanctional_email_settings();

        $this->mail = new MailMessage();
        $this->mail->subject("Test Email");
        switch ($type) {
            case 'emails-test':
                $this->mail = (new Test())->toMail();
                $this->sendEmail();
                break;
            case 'application-deployment-success':
                $application = Application::all()->first();
                $this->mail = (new DeploymentSuccess($application, 'test'))->toMail();
                $this->sendEmail();
                break;
            case 'application-deployment-failed':
                $application = Application::all()->first();
                $preview = ApplicationPreview::all()->first();
                if (!$preview) {
                    $preview = ApplicationPreview::create([
                        'application_id' => $application->id,
                        'pull_request_id' => 1,
                        'pull_request_html_url' => 'http://example.com',
                        'fqdn' => $application->fqdn,
                    ]);
                }
                $this->mail = (new DeploymentFailed($application, 'test'))->toMail();
                $this->sendEmail();
                $this->mail = (new DeploymentFailed($application, 'test', $preview))->toMail();
                $this->sendEmail();
                break;
            case 'application-status-changed':
                $application = Application::all()->first();
                $this->mail = (new StatusChanged($application))->toMail();
                $this->sendEmail();
                break;
            case 'backup-failed':
                $backup = ScheduledDatabaseBackup::all()->first();
                $db = StandalonePostgresql::all()->first();
                if (!$backup) {
                    $backup = ScheduledDatabaseBackup::create([
                        'enabled' => true,
                        'frequency' => 'daily',
                        'save_s3' => false,
                        'database_id' => $db->id,
                        'database_type' => $db->getMorphClass(),
                        'team_id' => 0,
                    ]);
                }
                $output = 'Because of an error, the backup of the database ' . $db->name . ' failed.';
                $this->mail = (new BackupFailed($backup, $db, $output))->toMail();
                $this->sendEmail();
                break;
            case 'backup-success':
                $backup = ScheduledDatabaseBackup::all()->first();
                $db = StandalonePostgresql::all()->first();
                if (!$backup) {
                    $backup = ScheduledDatabaseBackup::create([
                        'enabled' => true,
                        'frequency' => 'daily',
                        'save_s3' => false,
                        'database_id' => $db->id,
                        'database_type' => $db->getMorphClass(),
                        'team_id' => 0,
                    ]);
                }
                $this->mail = (new BackupSuccess($backup, $db))->toMail();
                $this->sendEmail();
                break;
            case 'invitation-link':
                $user = User::all()->first();
                $invitation = TeamInvitation::whereEmail($user->email)->first();
                if (!$invitation) {
                    $invitation = TeamInvitation::create([
                        'uuid' => Str::uuid(),
                        'email' => $user->email,
                        'team_id' => 1,
                        'link' => 'http://example.com',
                    ]);
                }
                $this->mail = (new InvitationLink($user))->toMail();
                $this->sendEmail();
                break;
            case 'waitlist-invitation-link':
                $this->mail = new MailMessage();
                $this->mail->view('emails.waitlist-invitation', [
                    'loginLink' => 'https://coolify.io',
                ]);
                $this->mail->subject('Congratulations! You are invited to join Coolify Cloud.');
                $this->sendEmail();
                break;
            case 'waitlist-confirmation':
                $found = Waitlist::where('email', $this->email)->first();
                if ($found) {
                    SendConfirmationForWaitlistJob::dispatch($this->email, $found->uuid);

                } else {
                    throw new Exception('Waitlist not found');
                }

                break;
        }
    }
    private function sendEmail()
    {
        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->to($this->email)
                ->subject($this->mail->subject)
                ->html((string)$this->mail->render())
        );
    }
}
