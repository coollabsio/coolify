<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Waitlist;
use Illuminate\Console\Command;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InviteFromWaitlist extends Command
{
    public Waitlist|null $next_patient = null;
    public User|null $new_user = null;
    public string|null $password = null;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:invite-from-waitlist';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send invitation to the next user in the waitlist';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->next_patient = Waitlist::orderBy('created_at', 'asc')->where('verified', true)->first();
        if ($this->next_patient) {
            $this->register_user();
            $this->remove_from_waitlist();
            $this->send_email();
        } else {
            $this->info('No one in the waitlist who is verified. 👀');
        }
    }
    private function register_user()
    {
        $already_registered = User::whereEmail($this->next_patient->email)->first();
        if (!$already_registered) {
            $this->password = Str::password();
            $this->new_user = User::create([
                'name' => Str::of($this->next_patient->email)->before('@'),
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
        $this->info("User removed from waitlist successfully.");
    }
    private function send_email()
    {
        $mail = new MailMessage();
        $mail->view('emails.waitlist-invitation', [
            'email' => $this->next_patient->email,
            'password' => $this->password,
        ]);
        $mail->subject('Congratulations! You are invited to join Coolify Cloud.');
        send_user_an_email($mail, $this->next_patient->email);
        $this->info("Email sent successfully. 📧");
    }
}
