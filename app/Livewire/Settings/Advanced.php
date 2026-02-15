<?php



namespace App\\Livewire\\Settings;



use App\\Models\\InstanceSettings;

use Livewire\\Component;



class Advanced extends Component
    
{
    
    public InstanceSettings $settings;
    
    public bool $is_registration_enabled;
    
    public bool $is_oauth_registration_enabled;
    

    
    public function updatedIsRegistrationEnabled()
    
    {
        
        $this->settings->is_registration_enabled = $this->is_registration_enabled;
        
        $this->settings->save();
        
    }
    

    
    public function updatedIsOauthRegistrationEnabled()
    
    {
        
        $this->settings->is_oauth_registration_enabled = $this->is_oauth_registration_enabled;
        
        $this->settings->save();
        
    }
    

    
    public function mount()
    
    {
        
        $this->is_registration_enabled = $this->settings->is_registration_enabled;
        
        $this->is_oauth_registration_enabled = $this->settings->is_oauth_registration_enabled;
        
    }
    

    
    public function render()
    
    {
        
        return view('livewire.settings.advanced');
        
    }
    
}



























