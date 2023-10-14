<?php

namespace App\Http\Livewire\Project\Database\Redis;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Models\StandaloneRedis;
use Exception;
use Livewire\Component;

class General extends Component
{
    protected $listeners = ['refresh'];

    public StandaloneRedis $database;
    public string $db_url;

    protected $rules = [
        'database.name' => 'required',
        'database.description' => 'nullable',
        'database.redis_conf' => 'nullable',
        'database.redis_password' => 'required',
        'database.image' => 'required',
        'database.ports_mappings' => 'nullable',
        'database.is_public' => 'nullable|boolean',
        'database.public_port' => 'nullable|integer',
    ];
    protected $validationAttributes = [
        'database.name' => 'Name',
        'database.description' => 'Description',
        'database.redis_conf' => 'Redis Configuration',
        'database.redis_password' => 'Redis Password',
        'database.image' => 'Image',
        'database.ports_mappings' => 'Port Mapping',
        'database.is_public' => 'Is Public',
        'database.public_port' => 'Public Port',
    ];
    public function submit() {
        try {
            $this->validate();
            if ($this->database->redis_conf === "") {
                $this->database->redis_conf = null;
            }
            $this->database->save();
            $this->emit('success', 'Database updated successfully.');
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }
    public function instantSave()
    {
        try {
            if ($this->database->is_public && !$this->database->public_port) {
                $this->emit('error', 'Public port is required.');
                $this->database->is_public = false;
                return;
            }
            if ($this->database->is_public) {
                $this->emit('success', 'Starting TCP proxy...');
                StartDatabaseProxy::run($this->database);
                $this->emit('success', 'Database is now publicly accessible.');
            } else {
                StopDatabaseProxy::run($this->database);
                $this->emit('success', 'Database is no longer publicly accessible.');
            }
            $this->getDbUrl();
            $this->database->save();
        } catch(\Throwable $e) {
            $this->database->is_public = !$this->database->is_public;
            return handleError($e, $this);
        }
    }
    public function refresh(): void
    {
        $this->database->refresh();
    }

    public function mount()
    {
        $this->getDbUrl();
    }
    public function getDbUrl() {

        if ($this->database->is_public) {
            $this->db_url = "redis://{$this->database->redis_password}@{$this->database->destination->server->getIp}:{$this->database->public_port}/0";
        } else {
            $this->db_url = "redis://{$this->database->redis_password}@{$this->database->uuid}:5432/0";
        }
    }
    public function render()
    {
        return view('livewire.project.database.redis.general');
    }
}
