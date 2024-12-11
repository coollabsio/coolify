<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Notifications\Application\DeploymentFailed;
use App\Notifications\Application\DeploymentSuccess;
use App\Notifications\Application\StatusChanged;
use App\Notifications\Database\BackupFailed;
use App\Notifications\Database\BackupSuccess;
use App\Notifications\Test;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Mail;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class Emails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'emails';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send out test / prod emails';

    /**
     * Execute the console command.
     */
    private ?MailMessage $mail = null;

    private ?string $email = null;

    public function handle()
    {
        $type = select(
            'Which Email should be sent?',
            options: [
                'updates' => 'Send Update Email to all users',
                'emails-test' => 'Test',
                'database-backup-statuses-daily' => 'Database - Backup Statuses (Daily)',
                'application-deployment-success-daily' => 'Application - Deployment Success (Daily)',
                'application-deployment-success' => 'Application - Deployment Success',
                'application-deployment-failed' => 'Application - Deployment Failed',
                'application-status-changed' => 'Application - Status Changed',
                'backup-success' => 'Database - Backup Success',
                'backup-failed' => 'Database - Backup Failed',
                // 'invitation-link' => 'Invitation Link',
                'realusers-before-trial' => 'REAL - Registered Users Before Trial without Subscription',
                'realusers-server-lost-connection' => 'REAL - Server Lost Connection',
            ],
        );
        $emailsGathered = ['realusers-before-trial', 'realusers-server-lost-connection'];
        if (isDev()) {
            $this->email = 'test@example.com';
        } else {
            if (! in_array($type, $emailsGathered)) {
                $this->email = text('Email Address to send to:');
            }
        }
        set_transanctional_email_settings();

        $this->mail = new MailMessage;
        $this->mail->subject('Test Email');
        switch ($type) {
            case 'updates':
                $teams = Team::all();
                if (! $teams || $teams->isEmpty()) {
                    echo 'No teams found.'.PHP_EOL;

                    return;
                }
                $emails = [];
                foreach ($teams as $team) {
                    foreach ($team->members as $member) {
                        if ($member->email && $member->marketing_emails) {
                            $emails[] = $member->email;
                        }
                    }
                }
                $emails = array_unique($emails);
                $this->info('Sending to '.count($emails).' emails.');
                foreach ($emails as $email) {
                    $this->info($email);
                }
                $confirmed = confirm('Are you sure?');
                if ($confirmed) {
                    foreach ($emails as $email) {
                        $this->mail = new MailMessage;
                        $this->mail->subject('One-click Services, Docker Compose support');
                        $unsubscribeUrl = route('unsubscribe.marketing.emails', [
                            'token' => encrypt($email),
                        ]);
                        $this->mail->view('emails.updates', ['unsubscribeUrl' => $unsubscribeUrl]);
                        $this->sendEmail($email);
                    }
                }
                break;
            case 'emails-test':
                $this->mail = (new Test)->toMail();
                $this->sendEmail();
                break;
            case 'application-deployment-success-daily':
                $applications = Application::all();
                foreach ($applications as $application) {
                    $deployments = $application->get_last_days_deployments();
                    if ($deployments->isEmpty()) {
                        continue;
                    }
                    $this->mail = (new DeploymentSuccess($application, 'test'))->toMail();
                    $this->sendEmail();
                }
                break;
            case 'application-deployment-success':
                $application = Application::all()->first();
                $this->mail = (new DeploymentSuccess($application, 'test'))->toMail();
                $this->sendEmail();
                break;
            case 'application-deployment-failed':
                $application = Application::all()->first();
                $preview = ApplicationPreview::all()->first();
                if (! $preview) {
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
                if (! $backup) {
                    $backup = ScheduledDatabaseBackup::create([
                        'enabled' => true,
                        'frequency' => 'daily',
                        'save_s3' => false,
                        'database_id' => $db->id,
                        'database_type' => $db->getMorphClass(),
                        'team_id' => 0,
                    ]);
                }
                $output = 'Because of an error, the backup of the database '.$db->name.' failed.';
                $this->mail = (new BackupFailed($backup, $db, $output))->toMail();
                $this->sendEmail();
                break;
            case 'backup-success':
                $backup = ScheduledDatabaseBackup::all()->first();
                $db = StandalonePostgresql::all()->first();
                if (! $backup) {
                    $backup = ScheduledDatabaseBackup::create([
                        'enabled' => true,
                        'frequency' => 'daily',
                        'save_s3' => false,
                        'database_id' => $db->id,
                        'database_type' => $db->getMorphClass(),
                        'team_id' => 0,
                    ]);
                }
                //$this->mail = (new BackupSuccess($backup->frequency, $db->name))->toMail();
                $this->sendEmail();
                break;
                // case 'invitation-link':
                //     $user = User::all()->first();
                //     $invitation = TeamInvitation::whereEmail($user->email)->first();
                //     if (!$invitation) {
                //         $invitation = TeamInvitation::create([
                //             'uuid' => Str::uuid(),
                //             'email' => $user->email,
                //             'team_id' => 1,
                //             'link' => 'http://example.com',
                //         ]);
                //     }
                //     $this->mail = (new InvitationLink($user))->toMail();
                //     $this->sendEmail();
                //     break;
            case 'realusers-before-trial':
                $this->mail = new MailMessage;
                $this->mail->view('emails.before-trial-conversion');
                $this->mail->subject('Trial period has been added for all subscription plans.');
                $teams = Team::doesntHave('subscription')->where('id', '!=', 0)->get();
                if (! $teams || $teams->isEmpty()) {
                    echo 'No teams found.'.PHP_EOL;

                    return;
                }
                $emails = [];
                foreach ($teams as $team) {
                    foreach ($team->members as $member) {
                        if ($member->email) {
                            $emails[] = $member->email;
                        }
                    }
                }
                $emails = array_unique($emails);
                $this->info('Sending to '.count($emails).' emails.');
                foreach ($emails as $email) {
                    $this->info($email);
                }
                $confirmed = confirm('Are you sure?');
                if ($confirmed) {
                    foreach ($emails as $email) {
                        $this->sendEmail($email);
                    }
                }
                break;
            case 'realusers-server-lost-connection':
                $serverId = text('Server Id');
                $server = Server::find($serverId);
                if (! $server) {
                    throw new Exception('Server not found');
                }
                $admins = [];
                $members = $server->team->members;
                foreach ($members as $member) {
                    if ($member->isAdmin()) {
                        $admins[] = $member->email;
                    }
                }
                $this->info('Sending to '.count($admins).' admins.');
                foreach ($admins as $admin) {
                    $this->info($admin);
                }
                $this->mail = new MailMessage;
                $this->mail->view('emails.server-lost-connection', [
                    'name' => $server->name,
                ]);
                $this->mail->subject('Action required: Server '.$server->name.' lost connection.');
                foreach ($admins as $email) {
                    $this->sendEmail($email);
                }
                break;
        }
    }

    private function sendEmail(?string $email = null)
    {
        if ($email) {
            $this->email = $email;
        }
        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->to($this->email)
                ->subject($this->mail->subject)
                ->html((string) $this->mail->render())
        );
        $this->info("Email sent to $this->email successfully. 📧");
    }
}
