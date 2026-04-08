<?php

namespace App\Livewire\User;

use App\Models\Project;
use App\Models\User;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public string $email = '';

    public bool $isClient = true;

    /** @var array<int, int> */
    public array $assignedProjectIds = [];

    public $availableProjects = [];

    public ?string $generatedPassword = null;

    public ?string $createdEmail = null;

    public ?string $loginUrl = null;

    public bool $emailSent = false;

    public ?string $emailError = null;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'isClient' => ['boolean'],
            'assignedProjectIds' => ['array'],
            'assignedProjectIds.*' => ['integer', 'exists:projects,id'],
        ];
    }

    public function mount(): void
    {
        $this->authorize('create', User::class);
        $this->availableProjects = Project::ownedByCurrentTeamCached();
    }

    public function submit(): void
    {
        $this->authorize('create', User::class);
        $this->validate();

        try {
            // Validate that every assigned project actually belongs to the
            // current team — guard against tampered request payloads.
            $teamId = currentTeam()->id;
            $assignedProjects = Project::query()
                ->whereIn('id', $this->assignedProjectIds)
                ->where('team_id', $teamId)
                ->get();

            if ($assignedProjects->count() !== count($this->assignedProjectIds)) {
                $this->addError('assignedProjectIds', 'One or more selected projects do not belong to the current team.');

                return;
            }

            $plainPassword = Str::password(length: 20, symbols: true);

            $user = new User;
            $user->name = $this->name;
            $user->email = $this->email;
            $user->password = Hash::make($plainPassword);
            // Setting protected attributes via direct assignment is allowed;
            // mass-assignment of these is intentionally blocked at the model.
            $user->is_client = $this->isClient;
            $user->force_password_reset = false;
            $user->email_verified_at = now();
            $user->save();

            // Attach the user to the current team. Clients are 'member',
            // non-client teammates can be promoted later via the Teams screen.
            $user->teams()->attach(currentTeam()->id, ['role' => 'member']);

            if ($this->isClient && $assignedProjects->isNotEmpty()) {
                $user->assignedProjects()->sync($assignedProjects->pluck('id')->all());
            }

            $this->generatedPassword = $plainPassword;
            $this->createdEmail = $user->email;
            $this->loginUrl = config('app.url').'/login';

            $this->sendCredentialsEmail($user, $plainPassword, $this->loginUrl);

            $this->dispatch('success', 'Usuario creado correctamente.');

            $this->reset(['name', 'email', 'assignedProjectIds']);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function sendCredentialsEmail(User $user, string $plainPassword, string $loginUrl): void
    {
        try {
            if (! is_transactional_emails_enabled()) {
                $this->emailError = 'El correo transaccional no está configurado. Comparte la contraseña manualmente con el usuario.';

                return;
            }

            $mail = new MailMessage;
            $mail->view('emails.new-user-credentials', [
                'name' => $user->name,
                'email' => $user->email,
                'password' => $plainPassword,
                'loginUrl' => $loginUrl,
                'instanceName' => config('app.name'),
            ]);
            $mail->subject('Bienvenido a '.config('app.name'));

            send_user_an_email($mail, $user->email);

            $this->emailSent = true;
        } catch (\Throwable $e) {
            $this->emailError = 'No se pudo enviar el email: '.$e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.user.create');
    }
}
