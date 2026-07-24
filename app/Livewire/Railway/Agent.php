<?php

namespace App\Livewire\Railway;

use App\Models\Environment;
use App\Models\Project;
use App\Services\Railway\AgentService;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Railway assistant chat panel. Docks on the right of the canvas, holds the
 * conversation, and drives the {@see AgentService} agent loop. Mutating actions
 * the model requests are surfaced as confirm cards the user must approve.
 */
class Agent extends Component
{
    public Project $project;

    public Environment $environment;

    public bool $open = false;

    public bool $configured = false;

    public string $input = '';

    /** @var array<int, array{role: string, text: string}> visible transcript */
    public array $transcript = [];

    /** @var array<int, array<string, mixed>> raw Anthropic message history */
    public array $messages = [];

    public ?string $error = null;

    // Confirmation state (set when the model requests a write action).
    /** @var array<int, array<string, mixed>> */
    public array $pending = [];

    /** @var array<int, array<string, mixed>> */
    public array $pendingAssistant = [];

    /** @var array<int, array<string, mixed>> */
    public array $pendingReadResults = [];

    public function mount(Environment $environment, Project $project): void
    {
        $this->environment = $environment;
        $this->project = $project;
        $this->configured = AgentService::isConfigured();
    }

    #[On('openRailwayAgent')]
    public function openAgent(): void
    {
        $this->open = true;
        $this->configured = AgentService::isConfigured();
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function send(): void
    {
        $text = trim($this->input);
        if ($text === '' || $this->pending !== [] || ! $this->configured) {
            return;
        }

        $this->error = null;
        $this->transcript[] = ['role' => 'user', 'text' => $text];
        $this->messages[] = ['role' => 'user', 'content' => $text];
        $this->input = '';

        $this->handle($this->service()->converse($this->messages));
    }

    public function approve(): void
    {
        $this->resolvePending(true);
    }

    public function deny(): void
    {
        $this->resolvePending(false);
    }

    private function resolvePending(bool $approved): void
    {
        if ($this->pending === []) {
            return;
        }

        $decision = [];
        foreach ($this->pending as $p) {
            $decision[$p['id']] = $approved;
            $this->transcript[] = [
                'role' => 'system',
                'text' => ($approved ? 'Approved: ' : 'Declined: ').$p['summary'],
            ];
        }

        $result = $this->service()->continueAfterConfirm(
            $this->messages,
            $this->pendingAssistant,
            $this->pendingReadResults,
            $this->pending,
            $decision,
        );

        $this->clearPending();
        $this->handle($result);
    }

    /** @param array<string, mixed> $result */
    private function handle(array $result): void
    {
        $this->messages = $result['messages'] ?? $this->messages;

        switch ($result['type']) {
            case 'final':
                if (($result['text'] ?? '') !== '') {
                    $this->transcript[] = ['role' => 'assistant', 'text' => $result['text']];
                }
                break;

            case 'confirm':
                if (($result['text'] ?? '') !== '') {
                    $this->transcript[] = ['role' => 'assistant', 'text' => $result['text']];
                }
                $this->pending = $result['pending'];
                $this->pendingAssistant = $result['assistant'];
                $this->pendingReadResults = $result['read_results'];
                break;

            case 'error':
            default:
                $this->error = $result['text'] ?? 'Something went wrong.';
                break;
        }
    }

    private function clearPending(): void
    {
        $this->pending = [];
        $this->pendingAssistant = [];
        $this->pendingReadResults = [];
    }

    private function service(): AgentService
    {
        return new AgentService($this->environment);
    }

    public function render()
    {
        return view('livewire.railway.agent');
    }
}
