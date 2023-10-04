<?php

namespace App\Http\Livewire\Project\Database\Postgresql;

use App\Models\StandalonePostgresql;
use Exception;
use Livewire\Component;

use function Aws\filter;

class General extends Component
{
    public StandalonePostgresql $database;
    public string $new_filename;
    public string $new_content;
    public string $db_url;

    protected $listeners = ['refresh', 'save_init_script', 'delete_init_script'];

    protected $rules = [
        'database.name' => 'required',
        'database.description' => 'nullable',
        'database.postgres_user' => 'required',
        'database.postgres_password' => 'required',
        'database.postgres_db' => 'required',
        'database.postgres_initdb_args' => 'nullable',
        'database.postgres_host_auth_method' => 'nullable',
        'database.init_scripts' => 'nullable',
        'database.image' => 'required',
        'database.ports_mappings' => 'nullable',
        'database.is_public' => 'nullable|boolean',
        'database.public_port' => 'nullable|integer',
    ];
    protected $validationAttributes = [
        'database.name' => 'Name',
        'database.description' => 'Description',
        'database.postgres_user' => 'Postgres User',
        'database.postgres_password' => 'Postgres Password',
        'database.postgres_db' => 'Postgres DB',
        'database.postgres_initdb_args' => 'Postgres Initdb Args',
        'database.postgres_host_auth_method' => 'Postgres Host Auth Method',
        'database.init_scripts' => 'Init Scripts',
        'database.image' => 'Image',
        'database.ports_mappings' => 'Port Mapping',
        'database.is_public' => 'Is Public',
        'database.public_port' => 'Public Port',
    ];
    public function mount()
    {
        $this->getDbUrl();
    }
    public function getDbUrl() {

        if ($this->database->is_public) {
            $this->db_url = "postgres://{$this->database->postgres_user}:{$this->database->postgres_password}@{$this->database->destination->server->getIp}:{$this->database->public_port}/{$this->database->postgres_db}";
        } else {
            $this->db_url = "postgres://{$this->database->postgres_user}:{$this->database->postgres_password}@{$this->database->uuid}:5432/{$this->database->postgres_db}";
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
                startPostgresProxy($this->database);
                $this->emit('success', 'Database is now publicly accessible.');
            } else {
                stopPostgresProxy($this->database);
                $this->emit('success', 'Database is no longer publicly accessible.');
            }
            $this->getDbUrl();
            $this->database->save();
        } catch(\Throwable $e) {
            $this->database->is_public = !$this->database->is_public;
            return handleError($e, $this);
        }

    }
    public function save_init_script($script)
    {
        $this->database->init_scripts = filter($this->database->init_scripts, fn ($s) => $s['filename'] !== $script['filename']);
        $this->database->init_scripts = array_merge($this->database->init_scripts, [$script]);
        $this->database->save();
        $this->emit('success', 'Init script saved successfully.');
    }

    public function delete_init_script($script)
    {
        $collection = collect($this->database->init_scripts);
        $found = $collection->firstWhere('filename', $script['filename']);
        if ($found) {
            ray($collection->filter(fn ($s) => $s['filename'] !== $script['filename'])->toArray());
            $this->database->init_scripts = $collection->filter(fn ($s) => $s['filename'] !== $script['filename'])->toArray();
            $this->database->save();
            $this->refresh();
            $this->emit('success', 'Init script deleted successfully.');
            return;
        }
    }

    public function refresh(): void
    {
        $this->database->refresh();
    }

    public function save_new_init_script()
    {
        $this->validate([
            'new_filename' => 'required|string',
            'new_content' => 'required|string',
        ]);
        $found = collect($this->database->init_scripts)->firstWhere('filename', $this->new_filename);
        if ($found) {
            $this->emit('error', 'Filename already exists.');
            return;
        }
        if (!isset($this->database->init_scripts)) {
            $this->database->init_scripts = [];
        }
        $this->database->init_scripts = array_merge($this->database->init_scripts, [
            [
                'index' => count($this->database->init_scripts),
                'filename' => $this->new_filename,
                'content' => $this->new_content,
            ]
        ]);
        $this->database->save();
        $this->emit('success', 'Init script added successfully.');
        $this->new_content = '';
        $this->new_filename = '';
    }

    public function submit()
    {
        try {
            $this->validate();
            $this->database->save();
            $this->emit('success', 'Database updated successfully.');
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }
}
