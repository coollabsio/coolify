<?php

namespace App\Http\Livewire\Project\Database\Postgresql;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Models\StandalonePostgresql;
use Exception;
use Livewire\Component;

use function Aws\filter;

class General extends Component
{
    public StandalonePostgresql $database;
    public string $new_filename;
    public string $new_content;
    public ?string $db_url = null;
    public ?string $db_url_public = null;

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
        $this->db_url = $this->database->getDbUrl(true);
        if ($this->database->is_public) {
            $this->db_url_public = $this->database->getDbUrl();
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
                if (!str($this->database->status)->startsWith('running')) {
                    $this->emit('error', 'Database must be started to be publicly accessible.');
                    $this->database->is_public = false;
                    return;
                }
                StartDatabaseProxy::run($this->database);
                $this->db_url_public = $this->database->getDbUrl();
                $this->emit('success', 'Database is now publicly accessible.');
            } else {
                StopDatabaseProxy::run($this->database);
                $this->db_url_public = null;
                $this->emit('success', 'Database is no longer publicly accessible.');
            }
            $this->database->save();
        } catch (\Throwable $e) {
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
            if (str($this->database->public_port)->isEmpty()) {
                $this->database->public_port = null;
            }
            $this->validate();
            $this->database->save();
            $this->emit('success', 'Database updated successfully.');
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }
}
