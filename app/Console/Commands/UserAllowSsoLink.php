<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;

class UserAllowSsoLink extends Command
{
    /**
     * @var string
     */
    protected $signature = 'user:allow-sso-link {email : The email address of the account} {--force : Do not ask for confirmation}';

    /**
     * @var string
     */
    protected $description = 'Let the next SSO sign-in link an account that an invitation created, and remove its password, passkeys, two-factor authentication, sessions, and API tokens';

    public function handle(): int
    {
        $user = User::whereEmail(Str::lower(trim((string) $this->argument('email'))))->first();
        if (! $user) {
            $this->error('No account has this email address.');

            return self::FAILURE;
        }

        if (! $user->created_by_invitation) {
            $this->error('An invitation did not create this account, or SSO can link it already.');

            return self::FAILURE;
        }

        $this->warn("This removes the password, passkeys, two-factor authentication, sessions, and API tokens of {$user->email}.");
        $this->warn('Do this only when you know that the owner of this email address asks for it.');
        if (! $this->option('force') && ! confirm('Continue?', default: false)) {
            return self::FAILURE;
        }

        $user->passkeys()->delete();
        $user->forceFill([
            'password' => null,
            'remember_token' => Str::random(60),
            'force_password_reset' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'created_by_invitation' => false,
        ])->save();
        $user->deleteAllSessions();

        auditLog('cli.user.sso_link_allowed', [
            'resource' => 'user',
            'target_user_id' => $user->id,
            'target_user_email' => $user->email,
        ]);

        $this->info("The next SSO sign-in of {$user->email} links this account.");

        return self::SUCCESS;
    }
}
