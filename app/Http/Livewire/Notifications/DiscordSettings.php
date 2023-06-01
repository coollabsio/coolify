<?php

namespace App\Http\Livewire\Notifications;

use App\Models\Server;
use App\Models\Team;
use App\Notifications\TestNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Component;

class DiscordSettings extends Component
{
    public Team|Server $model;

    protected $rules = [
        'model.smtp_attributes.discord_active' => 'nullable|boolean',
        'model.smtp_attributes.discord_webhook' => 'required|url',
    ];
    protected $validationAttributes = [
        'model.smtp_attributes.discord_webhook' => 'Discord Webhook',
    ];
    public function mount($model)
    {
        //
    }
    public function instantSave()
    {
        try {
            $this->submit();
        } catch (\Exception $e) {
            $this->model->smtp_attributes->discord_active = false;
            $this->addError('model.smtp_attributes.discord_webhook', $e->getMessage());
        }
    }
    private function saveModel()
    {
        $this->model->save();
        if (is_a($this->model, Team::class)) {
            session(['currentTeam' => $this->model]);
        }
    }
    public function submit()
    {
        $this->resetErrorBag();
        $this->validate();
        $this->saveModel();
    }
    public function sendTestNotification()
    {
        Notification::send($this->model, new TestNotification);
    }
}
