<?php

namespace App\Livewire\Settings;

use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\StandalonePostgresql;
use Livewire\Component;

class Index extends Component
{
    public InstanceSettings $settings;
    public StandalonePostgresql $database;
    public $s3s;
    public function mount()
    {
        if (isInstanceAdmin()) {
            $settings = InstanceSettings::get();
            $database = StandalonePostgresql::whereName('coolify-db')->first();
            if ($database) {
                if ($database->status !== 'running') {
                    $database->status = 'running';
                    $database->save();
                }
                $s3s = S3Storage::whereTeamId(0)->get();
            }
            $this->settings = $settings;
            $this->database = $database;
            $this->s3s = $s3s ?? [];
        } else {
            return redirect()->route('dashboard');
        }
    }
    public function render()
    {
        return view('livewire.settings.index');
    }
}
