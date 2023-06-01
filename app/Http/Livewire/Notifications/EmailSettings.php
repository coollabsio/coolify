<?php

namespace App\Http\Livewire\Notifications;

use App\Models\Server;
use App\Models\Team;
use App\Notifications\TestNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Component;

class EmailSettings extends Component
{
    public Team|Server $model;

    protected $rules = [
        'model.extra_attributes.smtp_active' => 'nullable|boolean',
        'model.extra_attributes.from_address' => 'required|email',
        'model.extra_attributes.from_name' => 'required',
        'model.extra_attributes.recipients' => 'required',
        'model.extra_attributes.smtp_host' => 'required',
        'model.extra_attributes.smtp_port' => 'required',
        'model.extra_attributes.smtp_encryption' => 'nullable',
        'model.extra_attributes.smtp_username' => 'nullable',
        'model.extra_attributes.smtp_password' => 'nullable',
        'model.extra_attributes.smtp_timeout' => 'nullable',
        'model.extra_attributes.test_notification_recipients' => 'nullable',
    ];
    protected $validationAttributes = [
        'model.extra_attributes.from_address' => '',
        'model.extra_attributes.from_name' => '',
        'model.extra_attributes.recipients' => '',
        'model.extra_attributes.smtp_host' => '',
        'model.extra_attributes.smtp_port' => '',
        'model.extra_attributes.smtp_encryption' => '',
        'model.extra_attributes.smtp_username' => '',
        'model.extra_attributes.smtp_password' => '',
        'model.extra_attributes.test_notification_recipients' => '',
    ];
    public function mount($model)
    {
        //
    }
    public function submit()
    {
        $this->resetErrorBag();
        $this->validate();
        $this->model->extra_attributes->recipients = str_replace(' ', '', $this->model->extra_attributes->recipients);
        $this->model->extra_attributes->test_notification_recipients = str_replace(' ', '', $this->model->extra_attributes->test_notification_recipients);
        $this->saveModel();
    }
    private function saveModel()
    {
        $this->model->save();
        if (is_a($this->model, Team::class)) {
            session(['currentTeam' => $this->model]);
        }
    }
    public function instantSave()
    {
        try {
            $this->submit();
        } catch (\Exception $e) {
            $this->model->extra_attributes->smtp_active = false;
            $this->validate();
        }
    }
}
