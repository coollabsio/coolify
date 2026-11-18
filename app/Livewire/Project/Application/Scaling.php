<?php

namespace App\Livewire\Project\Application;

use App\Enums\LoadBalancerStrategy;
use App\Livewire\Project\Shared\Destination;
use App\Models\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Scaling extends Component
{
    use AuthorizesRequests;

    public const STICKY_SESSION_DURATIONS = [
        '' => 'Browser session',
        '3600' => '1 hour',
        '86400' => '1 day',
        '2592000' => '30 days',
    ];

    public Application $application;

    #[Validate(['required', 'integer', 'min:1', 'max:'.Application::MAX_REPLICAS])]
    public int $replicas = 1;

    public LoadBalancerStrategy $loadBalancerStrategy = LoadBalancerStrategy::RoundRobin;

    public bool $isStickySessionEnabled = false;

    #[Validate(['required', 'regex:'.Application::STICKY_SESSION_COOKIE_NAME_PATTERN], message: ['regex' => 'Use lowercase letters, digits, - and _, and start with a letter.'])]
    public string $stickySessionCookieName = '';

    #[Validate(['nullable', 'integer', 'min:'.Application::MIN_STICKY_SESSION_MAX_AGE, 'max:'.Application::MAX_STICKY_SESSION_MAX_AGE])]
    public string $stickySessionMaxAge = '';

    protected $listeners = ['refresh' => '$refresh'];

    public function mount(): void
    {
        $this->replicas = $this->application->replicas;
        $this->loadBalancerStrategy = $this->application->load_balancer_strategy;
        $this->isStickySessionEnabled = $this->application->is_sticky_session_enabled;
        $this->stickySessionCookieName = $this->application->sticky_session_cookie_name;
        $this->stickySessionMaxAge = (string) $this->application->sticky_session_max_age;
    }

    public function submit(): void
    {
        $this->authorize('update', $this->application);
        $this->validate();

        try {
            $reason = $this->application->replicasUnavailableReason();
            if ($reason && $this->replicas > 1) {
                $this->dispatch('error', 'Failed to save replicas.', $reason);

                return;
            }
            if ($this->loadBalancerStrategy !== $this->application->load_balancer_strategy && ($reason = $this->application->loadBalancer()->strategyUnavailableReason($this->loadBalancerStrategy))) {
                $this->dispatch('error', 'Failed to save the load balancer.', $reason);

                return;
            }

            $this->application->update([
                'replicas' => $this->replicas,
                'load_balancer_strategy' => $this->loadBalancerStrategy,
                'is_sticky_session_enabled' => $this->isStickySessionEnabled,
                'sticky_session_cookie_name' => $this->stickySessionCookieName,
                'sticky_session_max_age' => filled($this->stickySessionMaxAge) ? (int) $this->stickySessionMaxAge : null,
            ]);
            if ($this->application->settings->is_container_label_readonly_enabled) {
                $this->application->update(['custom_labels' => base64_encode(implode("\n", generateLabelsApplication($this->application)))]);
            }
            $this->dispatch('success', 'Scaling updated.', 'Redeploy to apply the change.');
            $this->dispatch('configurationChanged');
            $this->dispatch('refresh')->to(Destination::class);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        $loadBalancer = $this->application->loadBalancer();
        $durations = self::STICKY_SESSION_DURATIONS;
        if (! array_key_exists($this->stickySessionMaxAge, $durations)) {
            $durations[$this->stickySessionMaxAge] = "{$this->stickySessionMaxAge} seconds";
        }

        return view('livewire.project.application.scaling', [
            'unavailableReason' => $this->application->replicasUnavailableReason(),
            'loadBalancerUnavailableReason' => $loadBalancer->unavailableReason(),
            'strategies' => collect(LoadBalancerStrategy::cases())->map(function (LoadBalancerStrategy $strategy) use ($loadBalancer): array {
                $reason = $loadBalancer->strategyUnavailableReason($strategy);

                return [
                    'value' => $strategy->value,
                    'label' => $strategy->label(),
                    'description' => $reason ?? $strategy->description(),
                    'disabled' => $reason !== null && $strategy !== $this->application->load_balancer_strategy,
                ];
            })->all(),
            'stickySessionDurations' => collect($durations)->map(fn (string $label, string|int $value): array => ['value' => (string) $value, 'label' => $label])->values()->all(),
            'supportsStickySessionMaxAge' => $loadBalancer->supportsStickySessionMaxAge(),
        ]);
    }
}
