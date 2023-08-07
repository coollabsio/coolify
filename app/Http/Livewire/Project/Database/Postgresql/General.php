<?php

namespace App\Http\Livewire\Project\Database\Postgresql;

use Livewire\Component;

class General extends Component
{
    public $database;
    protected $listeners = ['refresh'];

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
    ];
    public function refresh() {
        $this->database->refresh();
    }
    public function submit() {
        try {
            $this->validate();
            $this->database->save();
            $this->emit('success', 'Database updated successfully.');
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
