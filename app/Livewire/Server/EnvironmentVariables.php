<?php

namespace App\Livewire\Server;

use App\Models\Server;
use App\Models\ServerEnvironmentVariable;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class EnvironmentVariables extends Component
{
    public Server $server;
    
    public $environment_variables = [];
    
    public $new_environment_variable = [
        'key' => '',
        'value' => '',
        'is_literal' => false,
        'is_multiline' => false,
        'is_buildtime' => false,
        'is_runtime' => true,
    ];
    
    public $editing_environment_variable = null;
    
    public $show_new_form = false;
    
    protected $rules = [
        'new_environment_variable.key' => 'required|string',
        'new_environment_variable.value' => 'nullable|string',
        'new_environment_variable.is_literal' => 'boolean',
        'new_environment_variable.is_multiline' => 'boolean',
        'new_environment_variable.is_buildtime' => 'boolean',
        'new_environment_variable.is_runtime' => 'boolean',
    ];
    
    protected $validationAttributes = [
        'new_environment_variable.key' => 'key',
        'new_environment_variable.value' => 'value',
        'new_environment_variable.is_literal' => 'literal',
        'new_environment_variable.is_multiline' => 'multiline',
        'new_environment_variable.is_buildtime' => 'build time',
        'new_environment_variable.is_runtime' => 'runtime',
    ];
    
    public function mount(Server $server)
    {
        $this->server = $server;
        $this->loadEnvironmentVariables();
    }
    
    public function loadEnvironmentVariables()
    {
        $this->environment_variables = $this->server->environment_variables()->get()->toArray();
    }
    
    public function createEnvironmentVariable()
    {
        $this->validate();
        
        // Check if key already exists
        $existing = $this->server->environment_variables()->where('key', $this->new_environment_variable['key'])->first();
        if ($existing) {
            $this->addError('new_environment_variable.key', 'Environment variable with this key already exists.');
            return;
        }
        
        $this->server->environment_variables()->create([
            'uuid' => (string) new Cuid2,
            'key' => $this->new_environment_variable['key'],
            'value' => $this->new_environment_variable['value'],
            'is_literal' => $this->new_environment_variable['is_literal'],
            'is_multiline' => $this->new_environment_variable['is_multiline'],
            'is_buildtime' => $this->new_environment_variable['is_buildtime'],
            'is_runtime' => $this->new_environment_variable['is_runtime'],
        ]);
        
        $this->reset('new_environment_variable', 'show_new_form');
        $this->loadEnvironmentVariables();
        
        $this->dispatch('environment-variable-created');
    }
    
    public function startEdit($index)
    {
        $this->editing_environment_variable = $this->environment_variables[$index];
        $this->editing_environment_variable['index'] = $index;
    }
    
    public function cancelEdit()
    {
        $this->editing_environment_variable = null;
    }
    
    public function updateEnvironmentVariable()
    {
        $env = $this->editing_environment_variable;
        
        // Check if key already exists for another environment variable
        $existing = $this->server->environment_variables()
            ->where('key', $env['key'])
            ->where('id', '!=', $env['id'])
            ->first();
        if ($existing) {
            $this->addError('editing_environment_variable.key', 'Environment variable with this key already exists.');
            return;
        }
        
        $environmentVariable = ServerEnvironmentVariable::find($env['id']);
        $environmentVariable->update([
            'key' => $env['key'],
            'value' => $env['value'],
            'is_literal' => $env['is_literal'],
            'is_multiline' => $env['is_multiline'],
            'is_buildtime' => $env['is_buildtime'],
            'is_runtime' => $env['is_runtime'],
        ]);
        
        $this->editing_environment_variable = null;
        $this->loadEnvironmentVariables();
        
        $this->dispatch('environment-variable-updated');
    }
    
    public function deleteEnvironmentVariable($id)
    {
        $environmentVariable = ServerEnvironmentVariable::find($id);
        if ($environmentVariable) {
            $environmentVariable->delete();
            $this->loadEnvironmentVariables();
            $this->dispatch('environment-variable-deleted');
        }
    }
    
    public function render()
    {
        return view('livewire.server.environment-variables');
    }
}
