<?php

namespace App\Http\Livewire\Project\Database\Mongodb;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Models\StandaloneMongodb;
use Exception;
use Livewire\Component;

class General extends Component
{
    protected $listeners = ['refresh'];

    public StandaloneMongodb $database;
    public string $db_url;

    protected $rules = [
        'database.name' => 'required',
        'database.description' => 'nullable',
        'database.mongo_conf' => 'nullable',
        'database.mongo_initdb_root_username' => 'required',
        'database.mongo_initdb_root_password' => 'required',
        'database.mongo_initdb_database' => 'required',
        'database.image' => 'required',
        'database.ports_mappings' => 'nullable',
        'database.is_public' => 'nullable|boolean',
        'database.public_port' => 'nullable|integer',
    ];
    protected $validationAttributes = [
        'database.name' => 'Name',
        'database.description' => 'Description',
        'database.mongo_conf' => 'Mongo Configuration',
        'database.mongo_initdb_root_username' => 'Root Username',
        'database.mongo_initdb_root_password' => 'Root Password',
        'database.mongo_initdb_database' => 'Database',
        'database.image' => 'Image',
        'database.ports_mappings' => 'Port Mapping',
        'database.is_public' => 'Is Public',
        'database.public_port' => 'Public Port',
    ];
    public function submit() {
        try {
            $this->validate();
            if ($this->database->mongo_conf === "") {
                $this->database->mongo_conf = null;
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
            $this->db_url = "mongodb://{$this->database->mongo_initdb_root_username}:{$this->database->mongo_initdb_root_password}@{$this->database->destination->server->getIp}:{$this->database->public_port}/?directConnection=true";
        } else {
            $this->db_url = "mongodb://{$this->database->mongo_initdb_root_username}:{$this->database->mongo_initdb_root_password}@{$this->database->uuid}:27017/?directConnection=true";
        }
    }
    public function render()
    {
        return view('livewire.project.database.mongodb.general');
    }
}
