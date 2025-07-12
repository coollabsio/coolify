<?php

namespace App\Livewire;

use Livewire\Component;

class GeneratedPasswordModal extends Component
{
    public $passwords = [];

    public $showModal = false;

    protected $listeners = ['showGeneratedPasswords'];

    public function getListeners()
    {
        $userId = auth()->id();

        return [
            "echo-private:user.{$userId},GeneratedPasswordsEvent" => 'showGeneratedPasswords',
            'showGeneratedPasswords' => 'showGeneratedPasswords',
        ];
    }

    public function mount()
    {
        // Check if there are generated passwords in the session
        if (session()->has('generated_passwords')) {
            $this->passwords = session('generated_passwords');
            $this->showModal = true;
        }
    }

    public function showGeneratedPasswords($event)
    {
        $passwords = $event['passwords'] ?? $event;
        $this->passwords = $passwords;
        $this->showModal = true;
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->passwords = [];
    }

    public function render()
    {
        return view('livewire.generated-password-modal');
    }
}
