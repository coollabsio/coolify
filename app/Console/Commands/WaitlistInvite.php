<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Waitlist;
use Illuminate\Console\Command;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WaitlistInvite extends Command
{
    public Waitlist|User|null $next_patient = null;

    public ?string $password = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'waitlist:invite {--people=1} {--only-email} {email?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send invitation to the next user (or by email) in the waitlist';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $people = $this->option('people');
        for ($i = 0; $i < $people; $i++) {
            $this->main();
        }
    }

    private function main()
    {
        if ($this->argument('email')) {
            if ($this->option('only-email')) {
                $this->next_patient = User::whereEmail($this->argument('email'))->first();
                $this->password = Str::password();
                $this->next_patient->update([
                    'password' => Hash::make($this->password),
                    'force_password_reset' => true,
                ]);
            } else {
                $this->next_patient = Waitlist::where('email', $this->argument('email'))->first();
            }
            if (! $this->next_patient) {
                $this->error("{$this->argument('email')} not found in the waitlist.");

                return;
            }
        } else {
            $this->next_patient = Waitlist::orderBy('created_at', 'asc')->where('verified', true)->first();
        }
        if ($this->next_patient) {
            if ($this->option('only-email')) {
                $this->send_email();

                return;
            }
            $this->register_user();
            $this->remove_from_waitlist();
            $this->send_email();
        } else {
            $this->info('No verified user found in the waitlist. 👀');
        }
    }

    private function register_user()
    {
        $already_registered = User::whereEmail($this->next_patient->email)->first();
        if (! $already_registered) {
            $this->password = Str::password();
            User::create([
                'name' => str($this->next_patient->email)->before('@'),
                'email' => $this->next_patient->email,
                'password' => Hash::make($this->password),
                'force_password_reset' => true,
            ]);
            $this->info("User registered ({$this->next_patient->email}) successfully. 🎉");
        } else {
            throw new \Exception('User already registered');
        }
    }

    private function remove_from_waitlist()
    {
        $this->next_patient->delete();
        $this->info('User removed from waitlist successfully.');
    }

    private function send_email()
    {
        $token = Crypt::encryptString("{$this->next_patient->email}@@@$this->password");
        $loginLink = route('auth.link', ['token' => $token]);
        $mail = new MailMessage;
        $mail->view('emails.waitlist-invitation', [
            'loginLink' => $loginLink,
        ]);
        $mail->subject('Congratulations! You are invited to join Coolify Cloud.');
        send_user_an_email($mail, $this->next_patient->email);
        $this->info('Email sent successfully. 📧');
    }
}
